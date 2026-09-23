<?php

declare(strict_types=1);

namespace CodexRuntime;

use Closure;
use CodexRuntime\Attachment\InboundAttachmentLocalizer;
use CodexRuntime\Attachment\VoiceAttachmentProcessor;
use CodexRuntime\Contracts\StatusMessageServiceInterface;
use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\Contracts\FinalResponseDeliveryInterface;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\ManagerQueue\ManagerQueueDispatcher;
use RuntimeException;
use Throwable;

final class ManagerWorker
{
    public function __construct(
        private Config $config,
        private Logger $logger,
        private EventRepository $events,
        private JsonFileStore $stateStore,
        private StatusMessageServiceInterface $statusMessages,
        private WorkerShutdownFlag $shutdown,
        private TransportClientInterface $transport,
        private CodexProcess $codex,
        private VoiceAttachmentProcessor $voice_attachments,
        private InboundAttachmentLocalizer $attachment_localizer,
        private LimitMonitor $limit_monitor,
        private FinalResponseDeliveryInterface $final_delivery,
        private ?Closure $start_standby = null
    ) {
    }

    public function run(): void
    {
        $slot = new ManagerWorkerSlot($this->config);
        if (!$slot->acquire()) {
            $this->logger->info('Manager worker capacity reached', [
                'pid' => getmypid(),
                'max_workers' => $slot->capacity(),
            ]);
            return;
        }

        $this->logger->info('Manager worker slot acquired', [
            'pid' => getmypid(),
            'slot' => $slot->number(),
            'max_workers' => $slot->capacity(),
        ]);
        try {
            $this->runWithSlot();
        } finally {
            $this->logger->info('Manager worker slot released', [
                'pid' => getmypid(),
                'slot' => $slot->number(),
                'max_workers' => $slot->capacity(),
            ]);
            $slot->release();
        }
    }

    private function runWithSlot(): void
    {
        $dispatcher = new ManagerQueueDispatcher($this->config, $this->events);
        $this->logger->info('Manager worker started');
        $pollIntervalMs = (int) $this->config->get('manager_queue', 'poll_interval_ms', 1000);

        while (true) {
            if ($this->shutdown->consumeIfRequested()) {
                $this->logger->info('Manager worker exiting for shutdown request');
                return;
            }

            $claim = $dispatcher->claimNext();
            if ($claim === null) {
                usleep($pollIntervalMs * 1000);
                continue;
            }

            $this->startStandbyWorker();
            try {
                $this->handleClaimedEvent($claim->running_path, $claim->event);
                while ($claim->session_id !== '') {
                    $next = $dispatcher->claimNextForSession($claim->session_id);
                    if ($next === null) {
                        break;
                    }
                    $this->handleClaimedEvent($next['running_path'], $next['event']);
                }
            } finally {
                $claim->release();
            }
        }
    }

    private function handleClaimedEvent(string $running_path, array $event): void
    {
        try {
            $event_id = (string) ($event['id'] ?? basename($running_path, '.json'));
            $this->markActive($event);
            $this->logger->info('Manager worker handling event', [
                'event_id' => $event_id,
                'type' => $event['type'] ?? 'unknown',
                'priority' => $event['priority'] ?? null,
            ]);

            $result = $this->processEvent($event);
            $this->events->finish($running_path, !empty($result['ok']) ? 'done' : 'failed', $result);
            $this->clearActive(!empty($result['ok']));
        } catch (Throwable $error) {
            $this->logger->error('Manager worker error', ['error' => $error->getMessage()]);
            if (is_file($running_path)) {
                try {
                    $failed_event_id = (string) ($event['id'] ?? basename($running_path, '.json'));
                    $this->events->finish($running_path, 'failed', [
                        'ok' => false,
                        'stdout' => '',
                        'stderr' => $error->getMessage(),
                        'event_type' => (string) ($event['type'] ?? 'unknown'),
                        'event_id' => $failed_event_id,
                    ]);
                } catch (Throwable $finish_error) {
                    $this->logger->error('Manager worker failed to finalize errored event', [
                        'error' => $finish_error->getMessage(),
                        'running_path' => $running_path,
                    ]);
                }
            }
            $this->clearActive(false);
        }
    }

    private function processEvent(array $event): array
    {
        return match ((string) ($event['type'] ?? '')) {
            'user_message' => $this->processUserMessage($event),
            'scheduled_prompt' => $this->processScheduledPrompt($event),
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

        $attachments = is_array($event['meta']['attachments'] ?? null) ? $event['meta']['attachments'] : [];
        try {
            $processed = $this->voice_attachments->process((string) ($event['text'] ?? ''), $attachments);
        } catch (Throwable $error) {
            $this->sendMessage(
                $runtimeSessionId,
                'Не удалось расшифровать голосовое сообщение. Проверьте transcription.api_key и повторите отправку.',
                null,
                null
            );
            throw $error;
        }
        if ($processed['transcript'] !== '') {
            $this->transport->sendTranscript($runtimeSessionId, $processed['transcript']);
        }
        $localized = $this->attachment_localizer->localize($runtimeSessionId, $processed['attachments']);
        $text = AttachmentPromptFormatter::prependAttachments($processed['text'], $localized);
            if ($text === '') {
                throw new RuntimeException('Empty text for user_message');
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
        if ($outboundSessionId !== 'none') {
            $this->statusMessages->sendHeartbeat($outboundSessionId);
        }

        $result = $this->codex->run($prompt, $codexSessionId, $workingDir, function (string $partialText, string $latestChunk = '', bool $isProcessRunning = true) use ($outboundSessionId): void {
            if ($outboundSessionId !== 'none' && $latestChunk !== '' && $isProcessRunning) {
                $this->sendMessage(
                    $outboundSessionId,
                    $latestChunk,
                    null,
                    null,
                    true
                );
            }

            if ($outboundSessionId !== 'none') {
                $this->statusMessages->sendHeartbeat($outboundSessionId);
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

        $this->final_delivery->send($runtimeSessionId, $finalText);

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

        $this->statusMessages->sendHeartbeat($runtimeSessionId);

        $result = $this->codex->run(
            $this->buildScheduledPrompt($text, $event),
            $codexSessionId,
            $this->resolveWorkingDir(null),
            function (string $partialText, string $latestChunk = '', bool $isProcessRunning = true) use ($runtimeSessionId): void {
                if ($latestChunk !== '' && $isProcessRunning) {
                    $this->sendMessage($runtimeSessionId, $latestChunk, null, null, true);
                }

                $this->statusMessages->sendHeartbeat($runtimeSessionId);
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

        $this->final_delivery->send($runtimeSessionId, $finalText);

        return [
            'ok' => (($result['exit_code'] ?? 1) === 0),
            'stdout' => $finalText,
            'stderr' => (string) ($result['stderr'] ?? ''),
            'session_id' => $runtimeSessionId,
            'codex_session_id' => $finalCodexSessionId,
            'event_type' => 'scheduled_prompt',
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
        $this->statusMessages->sendHeartbeat($runtimeSessionId);

        $result = $this->codex->run(
            $prompt,
            $codexSessionId !== '' ? $codexSessionId : null,
            $this->resolveWorkingDir(null),
            function (string $partialText, string $latestChunk = '', bool $isProcessRunning = true) use ($runtimeSessionId): void {
                if ($latestChunk !== '' && $isProcessRunning) {
                    $this->sendMessage(
                        $runtimeSessionId,
                        $latestChunk,
                        null,
                        null,
                        true
                    );
                }

                $this->statusMessages->sendHeartbeat($runtimeSessionId);
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

        $this->final_delivery->send($runtimeSessionId, $finalText);

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

    private function buildUserPrompt(string $runtimeSessionId, string $userText, ?string $sessionId): string
    {
        if ($sessionId !== null && $sessionId !== '') {
            return $userText;
        }

        $bootstrap = trim((string) $this->config->get('codex', 'bootstrap_prompt', ''));
        $labelPrefix = (string) $this->config->get('codex', 'session_label_prefix', 'transport-channel-');
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

    private function resolveWorkingDir(int|string|null $chatId): string
    {
        return trim((string) $this->config->get('codex', 'cwd', '/home/web'));
    }

    private function sendMessage(
        int|string $sessionId,
        string $text,
        ?int $replyToMessageId = null,
        ?string $parseMode = null,
        bool $disableNotification = false
    ): ?int
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $message = $disableNotification
            ? $this->transport->sendMessage($sessionId, $text, $replyToMessageId, $parseMode, true)
            : $this->limit_monitor->sendFinal($sessionId, $text, $replyToMessageId, $parseMode);

        return isset($message['message_id']) ? (int) $message['message_id'] : null;
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

    private function clearActive(bool $notifyIdle = true): void
    {
        $state = $this->readManagerState();
        $activeSessionId = trim((string) ($state['active_session_id'] ?? ''));
        $activeTaskId = trim((string) ($state['active_task_id'] ?? ''));
        unset($state['active_task_id'], $state['active_type'], $state['active_priority'], $state['active_started_at']);
        $this->stateStore->write($state);
        if ($notifyIdle) {
            $this->statusMessages->updateWorkerIdle($activeSessionId);
            return;
        }

        if ($activeSessionId !== '') {
            $this->statusMessages->updateWorkerFailed($activeTaskId, $activeSessionId);
        }
    }

    private function readManagerState(): array
    {
        $state = $this->stateStore->read();

        return [
            'sessions' => is_array($state['sessions'] ?? null) ? $state['sessions'] : [],
            'active_task_id' => isset($state['active_task_id']) ? (string) $state['active_task_id'] : null,
            'active_type' => isset($state['active_type']) ? (string) $state['active_type'] : null,
            'active_priority' => isset($state['active_priority']) ? (int) $state['active_priority'] : null,
            'active_started_at' => isset($state['active_started_at']) ? (string) $state['active_started_at'] : null,
            'active_session_id' => isset($state['active_session_id']) ? (string) $state['active_session_id'] : null,
        ];
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

    private function startStandbyWorker(): void
    {
        if ($this->start_standby === null) {
            return;
        }

        ($this->start_standby)();
    }
}
