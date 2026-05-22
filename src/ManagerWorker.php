<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\DeliveryClientInterface;
use CodexRuntime\Contracts\StatusMessageServiceInterface;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\Router\RouterAuthException;
use CodexRuntime\Router\CoreEventSource;
use CodexRuntime\Router\RouterUnavailableException;
use RuntimeException;
use Throwable;

final class ManagerWorker
{
    private $lockHandle = null;
    private int $routerRetryNotBefore = 0;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private EventRepository $events,
        private JsonFileStore $stateStore,
        private StatusMessageServiceInterface $statusMessages,
        private WorkerShutdownFlag $shutdown,
        private DeliveryClientInterface $delivery,
        private CodexProcess $codex,
        private ?CoreEventSource $routerEvents = null
    ) {
    }

    public function run(): void
    {
        $this->acquireLock();
        $requeued = $this->events->requeueAllRunning();
        $this->logger->info('Manager worker started');
        if ($requeued !== []) {
            $this->logger->info('Requeued stale manager events', ['event_ids' => $requeued]);
        }
        $pollIntervalMs = (int) $this->config->get('manager_queue', 'poll_interval_ms', 1000);

        while (true) {
            if ($this->shutdown->consumeIfRequested()) {
                $this->logger->info('Manager worker exiting for shutdown request');
                return;
            }

            $runningPath = null;
            $event = null;
            $routerEventId = null;
            try {
                $event = $this->nextRouterEvent();
                if ($event !== null) {
                    $routerEventId = (int) ($event['router_event_id'] ?? 0);
                    $eventId = (string) ($event['id'] ?? ('router:' . $routerEventId));
                    $this->markActive($event);
                    $this->logger->info('Manager worker handling Router event', [
                        'event_id' => $eventId,
                        'router_event_id' => $routerEventId,
                        'type' => $event['type'] ?? 'unknown',
                        'priority' => $event['priority'] ?? null,
                    ]);

                    $this->processEvent($event);
                    $this->advanceRouterCursor($routerEventId);
                    $this->clearActive();
                    continue;
                }

                $nextPath = $this->events->nextPendingPath();
                if ($nextPath === null) {
                    usleep($pollIntervalMs * 1000);
                    continue;
                }

                $runningPath = $this->events->moveToRunning($nextPath);
                $event = $this->events->loadEvent($runningPath);
                $eventId = (string) ($event['id'] ?? basename($runningPath, '.json'));
                $this->markActive($event);
                $this->logger->info('Manager worker handling event', [
                    'event_id' => $eventId,
                    'type' => $event['type'] ?? 'unknown',
                    'priority' => $event['priority'] ?? null,
                ]);

                $result = $this->processEvent($event);
                $this->events->finish($runningPath, !empty($result['ok']) ? 'done' : 'failed', $result);
                $this->clearActive();
            } catch (Throwable $e) {
                $this->logger->error('Manager worker error', ['error' => $e->getMessage()]);
                if ($routerEventId !== null && $routerEventId > 0) {
                    try {
                        $this->advanceRouterCursor($routerEventId);
                    } catch (Throwable $cursorError) {
                        $this->logger->error('Manager worker failed to advance Router cursor after error', [
                            'router_event_id' => $routerEventId,
                            'error' => $cursorError->getMessage(),
                        ]);
                    }
                }
                if ($runningPath !== null && is_file($runningPath)) {
                    try {
                        $failedEventId = is_array($event) ? (string) ($event['id'] ?? basename($runningPath, '.json')) : basename($runningPath, '.json');
                        $this->events->finish($runningPath, 'failed', [
                            'ok' => false,
                            'stdout' => '',
                            'stderr' => $e->getMessage(),
                            'event_type' => is_array($event) ? (string) ($event['type'] ?? 'unknown') : 'unknown',
                            'event_id' => $failedEventId,
                        ]);
                    } catch (Throwable $finishError) {
                        $this->logger->error('Manager worker failed to finalize errored event', [
                            'error' => $finishError->getMessage(),
                            'running_path' => $runningPath,
                        ]);
                    }
                }
                $this->clearActive();
                usleep($pollIntervalMs * 1000);
            }
        }
    }

    private function processEvent(array $event): array
    {
        return match ((string) ($event['type'] ?? '')) {
            'user_message' => $this->processUserMessage($event),
            'scheduled_prompt' => $this->processScheduledPrompt($event),
            'internal_decision' => $this->processInternalDecision($event),
            'background_result' => $this->processBackgroundResult($event),
            default => [
                'ok' => false,
                'stdout' => '',
                'stderr' => 'Unknown manager event type',
                'type' => $event['type'] ?? null,
            ],
        };
    }

    private function processUserMessage(array $event): array
    {
        $text = trim((string) ($event['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('Empty text for user_message');
        }

        $runtimeSessionId = trim((string) ($event['session_id'] ?? $event['meta']['session_id'] ?? ''));
        if ($runtimeSessionId === '') {
            return [
                'ok' => false,
                'stdout' => '',
                'stderr' => 'Inbound runtime session is not configured',
                'session_id' => 'none',
                'event_type' => 'user_message',
            ];
        }

        $state = $this->readManagerState();
        $codexSessionId = $this->resolveCodexSessionId($state, $runtimeSessionId);
        $outboundSessionId = $runtimeSessionId;
        $stateChanged = $this->rememberSessionRoute(
            $state,
            $outboundSessionId,
            $codexSessionId
        );
        if ($stateChanged) {
            $this->stateStore->write($state);
        }
        $workingDir = $this->resolveWorkingDir(null);
        $prompt = $this->buildUserPrompt($runtimeSessionId, $text, $codexSessionId);
        $result = $this->codex->run($prompt, $codexSessionId, $workingDir, function (string $partialText, string $latestChunk = '', bool $isProcessRunning = true) use ($outboundSessionId): void {
            if ($outboundSessionId !== 'none' && $latestChunk !== '' && $isProcessRunning) {
                $this->sendChunkedMessages(
                    $outboundSessionId,
                    $latestChunk,
                    null,
                    null,
                    true
                );
            }

        }, $runtimeSessionId);

        $finalCodexSessionId = trim((string) ($result['session_id'] ?? '')) ?: $codexSessionId;
        if ($this->rememberSessionRoute(
            $state,
            $runtimeSessionId,
            $finalCodexSessionId
        )) {
            $this->stateStore->write($state);
        }

        $finalText = trim((string) ($result['text'] ?? ''));
        if ($finalText === '') {
            $finalText = 'Пустой ответ от Codex.';
        }

        $this->sendChunkedMessages($runtimeSessionId, $finalText, null, null);

        return [
            'ok' => (($result['exit_code'] ?? 1) === 0),
            'stdout' => $finalText,
            'stderr' => (string) ($result['stderr'] ?? ''),
            'session_id' => $runtimeSessionId,
            'codex_session_id' => $finalCodexSessionId,
            'event_type' => 'user_message',
        ];
    }

    private function processScheduledPrompt(array $event): array
    {
        $text = trim((string) ($event['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('Empty text for scheduled_prompt');
        }

        $runtimeSessionId = trim((string) ($event['session_id'] ?? $event['meta']['session_id'] ?? ''));
        if ($runtimeSessionId === '') {
            throw new RuntimeException('Missing session_id for scheduled_prompt');
        }

        $state = $this->readManagerState();
        $codexSessionId = $this->resolveCodexSessionId($state, $runtimeSessionId);
        if ($this->rememberSessionRoute($state, $runtimeSessionId, $codexSessionId)) {
            $this->stateStore->write($state);
        }

        $result = $this->codex->run(
            $this->buildScheduledPrompt($text, $event),
            $codexSessionId,
            $this->resolveWorkingDir(null),
            function (string $partialText, string $latestChunk = '', bool $isProcessRunning = true) use ($runtimeSessionId): void {
                if ($latestChunk !== '' && $isProcessRunning) {
                    $this->sendChunkedMessages($runtimeSessionId, $latestChunk, null, null, true);
                }
            },
            $runtimeSessionId
        );

        $finalCodexSessionId = trim((string) ($result['session_id'] ?? '')) ?: $codexSessionId;
        if ($this->rememberSessionRoute($state, $runtimeSessionId, $finalCodexSessionId)) {
            $this->stateStore->write($state);
        }

        $finalText = trim((string) ($result['text'] ?? ''));
        if ($finalText === '') {
            $finalText = 'Пустой ответ от Codex.';
        }

        $this->sendChunkedMessages($runtimeSessionId, $finalText, null, null);

        return [
            'ok' => (($result['exit_code'] ?? 1) === 0),
            'stdout' => $finalText,
            'stderr' => (string) ($result['stderr'] ?? ''),
            'session_id' => $runtimeSessionId,
            'codex_session_id' => $finalCodexSessionId,
            'event_type' => 'scheduled_prompt',
        ];
    }

    private function processInternalDecision(array $event): array
    {
        if ($this->isIdleWatchdogDemoEvent($event)) {
            return $this->processIdleWatchdogDemoEvent($event);
        }

        $prompt = trim((string) ($event['prompt'] ?? ''));
        if ($prompt === '') {
            throw new RuntimeException('Empty prompt for internal_decision');
        }

        $state = $this->readManagerState();
        $effectiveSessionId = $this->resolveSessionId($state, null);
        $streamingSource = $this->internalDecisionStreamingSource($event);
        $shouldStreamToTransport = $streamingSource !== null;
        $notificationSessionIds = $shouldStreamToTransport ? $this->notificationSessionIds() : [];
        $lastStreamedChunk = '';
        $result = $this->codex->run(
            $prompt,
            $effectiveSessionId === 'none' ? null : $effectiveSessionId,
            $this->resolveWorkingDir(null),
            function (string $partialText, string $latestChunk = '', bool $isProcessRunning = true) use ($shouldStreamToTransport, $streamingSource, $notificationSessionIds, &$lastStreamedChunk): void {
                if (!$shouldStreamToTransport || $streamingSource === null || $latestChunk === '') {
                    return;
                }

                if (!$isProcessRunning) {
                    return;
                }

                $lastStreamedChunk = trim($latestChunk);
                $renderedChunk = $this->renderInternalDecisionOutputMessage($streamingSource, $latestChunk);
                foreach ($notificationSessionIds as $sessionId) {
                    $this->sendChunkedMessages($sessionId, $renderedChunk, null, null, true);
                }
            },
            null
        );

        if (!empty($result['session_id'])) {
            $state['session_id'] = $result['session_id'];
            $this->stateStore->write($state);
        }

        $decisionText = trim((string) ($result['text'] ?? ''));
        $notification = $this->parseInternalDecisionNotification($decisionText);
        if ($streamingSource === 'idle_watchdog') {
            $outputText = $this->resolveIdleWatchdogOutputText($decisionText, $notification);
            if ($outputText !== '' && trim($outputText) !== $lastStreamedChunk) {
                $renderedText = $this->renderInternalDecisionOutputMessage($streamingSource, $outputText);
                foreach ($notificationSessionIds as $sessionId) {
                    $this->sendChunkedMessages($sessionId, $renderedText, null, null, true);
                }
            }

            if (!empty($notification['await_user_response'])) {
                $this->markWaitingForUserResponse((string) ($notification['message'] ?? $decisionText));
            }
        }

        if (!empty($notification['notify_user']) && !empty($notification['message'])) {
            foreach ($this->notificationSessionIds() as $sessionId) {
                $this->sendChunkedMessages($sessionId, (string) $notification['message'], null, null);
            }
        }

        if (!empty($notification['await_user_response'])) {
            $this->markWaitingForUserResponse((string) ($notification['message'] ?? $decisionText));
        }

        return [
            'ok' => (($result['exit_code'] ?? 1) === 0),
            'stdout' => $decisionText,
            'stderr' => (string) ($result['stderr'] ?? ''),
            'session_id' => $result['session_id'] ?? $effectiveSessionId,
            'event_type' => 'internal_decision',
            'notify_user' => !empty($notification['notify_user']),
            'await_user_response' => !empty($notification['await_user_response']),
            'notification_message' => (string) ($notification['message'] ?? ''),
        ];
    }

    private function processBackgroundResult(array $event): array
    {
        $runtimeSessionId = trim((string) ($event['session_id'] ?? ''));
        if ($runtimeSessionId === '') {
            throw new RuntimeException('Missing session_id for background_result');
        }

        $codexSessionId = trim((string) ($event['codex_session_id'] ?? ''));
        $jobId = trim((string) ($event['job_id'] ?? ''));
        $prompt = $this->buildBackgroundResultPrompt($event);
        $commentaryReplyTo = null;

        $result = $this->codex->run(
            $prompt,
            $codexSessionId !== '' ? $codexSessionId : null,
            $this->resolveWorkingDir(null),
            function (string $partialText, string $latestChunk = '', bool $isProcessRunning = true) use (&$commentaryReplyTo, $runtimeSessionId): void {
                if ($latestChunk !== '' && $isProcessRunning) {
                    $this->sendChunkedMessages(
                        $runtimeSessionId,
                        $latestChunk,
                        $commentaryReplyTo,
                        null,
                        true
                    );
                    $commentaryReplyTo = null;
                }

            },
            $runtimeSessionId
        );

        $finalCodexSessionId = trim((string) ($result['session_id'] ?? '')) ?: $codexSessionId;
        $state = $this->readManagerState();
        if ($this->rememberSessionRoute($state, $runtimeSessionId, $finalCodexSessionId)) {
            $this->stateStore->write($state);
        }

        $finalText = trim((string) ($result['text'] ?? ''));
        if ($finalText === '') {
            $finalText = sprintf(
                "Фоновая задача %s завершилась без текста результата.",
                $jobId !== '' ? $jobId : 'unknown'
            );
        }

        $this->sendChunkedMessages($runtimeSessionId, $finalText, null, null);

        return [
            'ok' => (($result['exit_code'] ?? 1) === 0),
            'stdout' => $finalText,
            'stderr' => (string) ($result['stderr'] ?? ''),
            'session_id' => $runtimeSessionId,
            'codex_session_id' => $finalCodexSessionId,
            'event_type' => 'background_result',
            'job_id' => $jobId,
        ];
    }

    private function buildScheduledPrompt(string $text, array $event): string
    {
        $text = trim($text);
        $createdAt = trim((string) ($event['meta']['scheduled_created_at'] ?? ''));
        $scheduledAt = trim((string) ($event['meta']['scheduled_at'] ?? ''));

        $header = 'SCHEDULER: настало время отложенной задачи или действия.';
        if ($createdAt !== '' || $scheduledAt !== '') {
            $parts = [];
            if ($createdAt !== '') {
                $parts[] = "поставлена {$createdAt}";
            }
            if ($scheduledAt !== '') {
                $parts[] = "запланирована на {$scheduledAt}";
            }
            $header .= "\n\nКонтекст: " . implode(', ', $parts) . '.';
        }

        return <<<TEXT
{$header}

Исходный отложенный prompt:
{$text}
TEXT;
    }

    private function isIdleWatchdogDecision(array $event): bool
    {
        return (($event['meta']['source'] ?? null) === 'idle_watchdog');
    }

    private function internalDecisionStreamingSource(array $event): ?string
    {
        $source = trim((string) ($event['meta']['source'] ?? ''));

        return match ($source) {
            'idle_watchdog' => $source,
            default => null,
        };
    }

    private function isIdleWatchdogDemoEvent(array $event): bool
    {
        return $this->isIdleWatchdogDecision($event) && trim((string) ($event['meta']['idle_watchdog_demo_text'] ?? '')) !== '';
    }

    private function processIdleWatchdogDemoEvent(array $event): array
    {
        $demoText = trim((string) ($event['meta']['idle_watchdog_demo_text'] ?? ''));
        $renderedText = $this->renderInternalDecisionOutputMessage('idle_watchdog', $demoText);
        foreach ($this->notificationSessionIds() as $sessionId) {
            $this->sendChunkedMessages($sessionId, $renderedText, null, null, true);
        }

        return [
            'ok' => true,
            'stdout' => $demoText,
            'stderr' => '',
            'session_id' => 'none',
            'event_type' => 'internal_decision',
            'notify_user' => false,
            'await_user_response' => false,
            'notification_message' => '',
        ];
    }

    /**
     * @param array{notify_user: bool, message: string, await_user_response: bool} $notification
     */
    private function resolveIdleWatchdogOutputText(string $decisionText, array $notification): string
    {
        $decisionText = trim($decisionText);
        if ($decisionText === '') {
            return '';
        }

        $decoded = json_decode($decisionText, true);
        if (is_array($decoded)) {
            return trim((string) ($notification['message'] ?? ''));
        }

        $message = trim((string) ($notification['message'] ?? ''));

        return $message !== '' ? $message : $decisionText;
    }

    private function renderInternalDecisionOutputMessage(string $source, string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $prefix = match ($source) {
            'idle_watchdog' => trim((string) $this->config->get('idle_watchdog', 'message_prefix', '⚙️')),
            default => '',
        };
        $payload = $prefix !== '' ? ($prefix . ' ' . $text) : $text;

        return $payload;
    }

    private function buildUserPrompt(string $runtimeSessionId, string $userText, ?string $sessionId): string
    {
        if ($sessionId !== null && $sessionId !== '') {
            return $userText;
        }

        $bootstrap = trim((string) $this->config->get('codex', 'bootstrap_prompt', ''));
        $labelPrefix = (string) $this->config->get('codex', 'session_label_prefix', 'runtime-channel-');
        $label = $labelPrefix . $runtimeSessionId;

        if ($bootstrap === '') {
            return $userText;
        }

        return "Служебная установка для новой сессии {$label}:\n{$bootstrap}\n\nСообщение пользователя:\n{$userText}";
    }

    private function buildBackgroundResultPrompt(array $event): string
    {
        $jobId = trim((string) ($event['job_id'] ?? ''));
        $command = trim((string) ($event['command'] ?? ''));
        $cwd = trim((string) ($event['cwd'] ?? ''));
        $ok = !empty($event['ok']) ? 'true' : 'false';
        $timedOut = !empty($event['timed_out']) ? 'true' : 'false';
        $exitCode = (string) ($event['exit_code'] ?? '');
        $resultBody = $this->buildBackgroundResultBody($event);

        return <<<TEXT
Завершилась фоновая задача, которую ты ранее поставил из этой же сессии.

Нужно:
1. Коротко сообщить пользователю результат.
2. Если есть важная ошибка или нужен следующий шаг, прямо сказать об этом.
3. Если результат очевиден и дополнительных действий не нужно, просто сообщить итог.

Данные задачи:
- job_id: {$jobId}
- ok: {$ok}
- timed_out: {$timedOut}
- exit_code: {$exitCode}
- cwd: {$cwd}
- command: {$command}

Результат:
{$resultBody}
TEXT;
    }

    private function buildBackgroundResultBody(array $event): string
    {
        $lastMessagePath = trim((string) ($event['last_message_path'] ?? ''));
        if ($lastMessagePath !== '') {
            $lastMessage = $this->readSmallTextFile($lastMessagePath);
            if ($lastMessage !== '') {
                return $lastMessage;
            }

            return "Короткий ответ воркера находится в файле: {$lastMessagePath}";
        }

        $resultPath = trim((string) ($event['result_path'] ?? ''));
        if ($resultPath !== '') {
            return "Результат фоновой задачи находится в файле: {$resultPath}";
        }

        return 'Подробный результат не был приложен к событию.';
    }

    private function readSmallTextFile(string $path): string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return '';
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return '';
        }

        return trim($contents);
    }

    private function resolveCodexSessionId(array $state, string $runtimeSessionId): ?string
    {
        $runtimeSessionId = trim($runtimeSessionId);
        if ($runtimeSessionId === '') {
            return null;
        }

        $raw = $state['sessions'][$runtimeSessionId] ?? '';
        $codexSessionId = is_array($raw)
            ? trim((string) ($raw['codex_session_id'] ?? ''))
            : trim((string) $raw);

        return $codexSessionId !== '' ? $codexSessionId : null;
    }

    private function resolveSessionId(array $state, int|string|null $chatId): string
    {
        $sessionId = '';
        $sessionId = trim((string) ($state['session_id'] ?? ''));
        if ($sessionId !== '') {
            return $sessionId;
        }

        $initial = trim((string) $this->config->get('codex', 'initial_session_id', ''));
        if ($initial !== '') {
            return $initial;
        }

        return 'none';
    }

    private function resolveWorkingDir(int|string|null $chatId): string
    {
        return trim((string) $this->config->get('codex', 'cwd', '/home/web'));
    }

    private function sendChunkedMessages(
        int|string $sessionId,
        string $text,
        ?int $replyToMessageId = null,
        ?string $parseMode = null,
        bool $disableNotification = false
    ): ?int
    {
        $chunks = $this->chunkText(
            $text,
            (int) $this->config->get('delivery', 'message_chunk_size', 3800)
        );
        $lastMessageId = null;
        foreach ($chunks as $index => $chunk) {
            $replyTo = $index === 0 ? $replyToMessageId : null;
            $message = $this->delivery->sendMessage($sessionId, $chunk, $replyTo, $parseMode, $disableNotification);
            $lastMessageId = isset($message['message_id']) ? (int) $message['message_id'] : $lastMessageId;
        }

        return $lastMessageId;
    }

    private function chunkText(string $text, int $chunkSize): array
    {
        $chunks = [];
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        while ($text !== '') {
            if (mb_strlen($text) <= $chunkSize) {
                $chunks[] = $text;
                break;
            }

            $slice = mb_substr($text, 0, $chunkSize);
            $breakPos = mb_strrpos($slice, "\n");
            if ($breakPos === false || $breakPos < (int) ($chunkSize * 0.5)) {
                $breakPos = mb_strrpos($slice, ' ');
            }
            if ($breakPos === false || $breakPos < (int) ($chunkSize * 0.5)) {
                $breakPos = $chunkSize;
            }

            $chunks[] = trim(mb_substr($text, 0, $breakPos));
            $text = ltrim(mb_substr($text, $breakPos));
        }

        return $chunks;
    }

    private function markActive(array $event): void
    {
        $state = $this->readManagerState();
        $state['active_task_id'] = (string) ($event['id'] ?? '');
        $state['active_type'] = (string) ($event['type'] ?? '');
        $state['active_priority'] = (int) ($event['priority'] ?? 50);
        $state['active_started_at'] = date(DATE_ATOM);
        $state['active_session_id'] = trim((string) ($event['session_id'] ?? ''));
        $this->stateStore->write($state);
        $this->statusMessages->updateWorkerBusy((string) $state['active_task_id'], $state['active_session_id']);
    }

    private function clearActive(): void
    {
        $state = $this->readManagerState();
        $activeSessionId = trim((string) ($state['active_session_id'] ?? ''));
        unset($state['active_task_id'], $state['active_type'], $state['active_priority'], $state['active_started_at']);
        $this->stateStore->write($state);
        $this->statusMessages->updateWorkerIdle($activeSessionId);
    }

    private function readManagerState(): array
    {
        $state = $this->stateStore->read();

        return [
            'sessions' => is_array($state['sessions'] ?? null) ? $state['sessions'] : [],
            'session_id' => (string) ($state['session_id'] ?? ''),
            'active_task_id' => isset($state['active_task_id']) ? (string) $state['active_task_id'] : null,
            'active_type' => isset($state['active_type']) ? (string) $state['active_type'] : null,
            'active_priority' => isset($state['active_priority']) ? (int) $state['active_priority'] : null,
            'active_started_at' => isset($state['active_started_at']) ? (string) $state['active_started_at'] : null,
            'active_session_id' => isset($state['active_session_id']) ? (string) $state['active_session_id'] : null,
            'waiting_for_user_response' => !empty($state['waiting_for_user_response']),
            'waiting_for_user_response_at' => isset($state['waiting_for_user_response_at']) ? (string) $state['waiting_for_user_response_at'] : null,
            'waiting_for_user_response_message' => isset($state['waiting_for_user_response_message']) ? (string) $state['waiting_for_user_response_message'] : null,
            'router_after_id' => isset($state['router_after_id']) ? (int) $state['router_after_id'] : 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nextRouterEvent(): ?array
    {
        if ($this->routerEvents === null) {
            return null;
        }

        if (time() < $this->routerRetryNotBefore) {
            return null;
        }

        $state = $this->readManagerState();
        try {
            return $this->routerEvents->pollNextEvent(
                (int) ($state['router_after_id'] ?? 0),
                (int) $this->config->get('router', 'core_events_wait_seconds', 0),
                (int) $this->config->get('router', 'core_events_limit', 1)
            );
        } catch (RouterAuthException $e) {
            throw $e;
        } catch (RouterUnavailableException $e) {
            $delaySeconds = max(1, (int) $this->config->get('router', 'retry_unavailable_after_seconds', 15));
            $this->routerRetryNotBefore = time() + $delaySeconds;
            $this->logger->warning('Router unavailable; falling back to local manager queue', [
                'error' => $e->getMessage(),
                'retry_after_seconds' => $delaySeconds,
            ]);

            return null;
        }
    }

    private function advanceRouterCursor(int $eventId): void
    {
        if ($eventId <= 0) {
            return;
        }

        $state = $this->readManagerState();
        $current = (int) ($state['router_after_id'] ?? 0);
        if ($eventId <= $current) {
            return;
        }

        $state['router_after_id'] = $eventId;
        $this->stateStore->write($state);
    }

    private function markWaitingForUserResponse(string $message): void
    {
        $state = $this->readManagerState();
        $state['waiting_for_user_response'] = true;
        $state['waiting_for_user_response_at'] = date(DATE_ATOM);
        $state['waiting_for_user_response_message'] = $message;
        $this->stateStore->write($state);
    }

    /**
     * @return array{notify_user: bool, message: string, await_user_response: bool}
     */
    private function parseInternalDecisionNotification(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['notify_user' => false, 'message' => '', 'await_user_response' => false];
        }

        $decoded = $this->extractInternalDecisionJsonPayload($text);
        if (is_array($decoded)) {
            return [
                'notify_user' => !empty($decoded['notify_user']),
                'message' => trim((string) ($decoded['message'] ?? '')),
                'await_user_response' => !empty($decoded['await_user_response']),
            ];
        }

        return [
            'notify_user' => str_contains($text, '?'),
            'message' => $text,
            'await_user_response' => false,
        ];
    }

    private function extractInternalDecisionJsonPayload(string $text): ?array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $length = strlen($text);
        for ($offset = $length - 1; $offset >= 0; $offset--) {
            if ($text[$offset] !== '{') {
                continue;
            }

            $candidate = trim(substr($text, $offset));
            if ($candidate === '') {
                continue;
            }

            $decoded = json_decode($candidate, true);
            if (!is_array($decoded)) {
                continue;
            }

            if (!array_key_exists('notify_user', $decoded) && !array_key_exists('message', $decoded) && !array_key_exists('await_user_response', $decoded)) {
                continue;
            }

            return $decoded;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function notificationSessionIds(): array
    {
        $state = $this->stateStore->read();
        $sessionIds = [];

        foreach ((array) ($state['sessions'] ?? []) as $sessionId => $route) {
            if ($sessionId === '') {
                continue;
            }
            $sessionIds[] = (string) $sessionId;
        }

        return array_values(array_unique(array_filter($sessionIds, static fn (string $sessionId): bool => $sessionId !== '')));
    }

    private function rememberSessionRoute(
        array &$state,
        string $runtimeSessionId,
        ?string $codexSessionId = null
    ): bool {
        $runtimeSessionId = trim($runtimeSessionId);
        if ($runtimeSessionId === '' || $runtimeSessionId === 'none') {
            return false;
        }

        $state['sessions'] ??= [];
        $current = trim((string) ($state['sessions'][$runtimeSessionId] ?? ''));
        $next = $current;
        $resolvedCodexSessionId = trim((string) ($codexSessionId ?? ''));
        if ($resolvedCodexSessionId !== '') {
            $next = $resolvedCodexSessionId;
        }

        if ($next === '') {
            unset($state['sessions'][$runtimeSessionId]);
        } else {
            $state['sessions'][$runtimeSessionId] = $next;
        }

        return $current !== $next;
    }

    private function acquireLock(): void
    {
        $lockFile = (string) $this->config->require('manager_queue', 'lock_file');
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$lockFile}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Manager worker is already running');
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);
        $this->lockHandle = $handle;

        $pidFile = (string) $this->config->require('background', 'manager_worker_pid_file');
        file_put_contents($pidFile, (string) getmypid());
    }
}
