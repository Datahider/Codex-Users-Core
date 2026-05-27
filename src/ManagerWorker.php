<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\StatusMessageServiceInterface;
use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\ManagerQueue\EventRepository;
use RuntimeException;
use Throwable;

final class ManagerWorker
{
    private $lockHandle = null;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private EventRepository $events,
        private JsonFileStore $stateStore,
        private StatusMessageServiceInterface $statusMessages,
        private WorkerShutdownFlag $shutdown,
        private TransportClientInterface $transport,
        private CodexProcess $codex
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
            try {
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
                $this->clearActive(!empty($result['ok']));
            } catch (Throwable $e) {
                $this->logger->error('Manager worker error', ['error' => $e->getMessage()]);
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
                $this->clearActive(false);
                usleep($pollIntervalMs * 1000);
            }
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

        $this->sendMessage($runtimeSessionId, $finalText, null, null);

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

        $this->sendMessage($runtimeSessionId, $finalText, null, null);

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
        $commentaryReplyTo = null;

        $this->statusMessages->sendHeartbeat($runtimeSessionId);

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

        $message = $this->transport->sendMessage($sessionId, $text, $replyToMessageId, $parseMode, $disableNotification);

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

    private function acquireLock(): void
    {
        $paths = new RuntimePaths($this->config);
        $lockFile = (string) $this->config->get('manager_queue', 'lock_file', $paths->workerLockFile('manager_worker'));
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

        $pidFile = (string) $this->config->get('background', 'manager_worker_pid_file', $paths->workerPidFile('manager_worker'));
        file_put_contents($pidFile, (string) getmypid());
    }
}
