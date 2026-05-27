<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\Contracts\TransportClientInterface;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class ControlWatcher
{
    private $lockHandle = null;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private CommandRepository $commands,
        private ActiveTurnRegistry $activeTurn,
        private JsonFileStore $stateStore,
        private TransportClientInterface $transport,
        private TransportMessageIngress $ingress,
        private CodexSessionCatalog $sessions,
        private WorkerShutdownFlag $shutdown
    ) {
    }

    public function run(): void
    {
        $this->acquireLock();
        $requeued = $this->commands->requeueAllRunning();
        $this->logger->info('Control watcher started');
        if ($requeued !== []) {
            $this->logger->warning('Requeued stale control commands', ['command_ids' => $requeued]);
        }

        $pollIntervalMs = (int) $this->config->get('control_queue', 'poll_interval_ms', 1000);

        while (true) {
            if ($this->shutdown->consumeIfRequested()) {
                $this->logger->info('Control watcher exiting for shutdown request');
                return;
            }

            $runningPath = null;
            $command = null;
            try {
                $path = $this->commands->nextPendingPath();
                if ($path === null) {
                    usleep($pollIntervalMs * 1000);
                    continue;
                }

                $runningPath = $this->commands->moveToRunning($path);
                $command = $this->commands->loadCommand($runningPath);
                $commandId = (string) ($command['id'] ?? basename($runningPath, '.json'));

                $this->logger->info('Control command received', [
                    'command_id' => $commandId,
                    'type' => $command['type'] ?? null,
                    'command' => $command,
                ]);

                $result = $this->processCommand($command);
                $this->commands->finish($runningPath, 'done', $result);

                $this->logger->info('Control command processed', [
                    'command_id' => $commandId,
                    'type' => $command['type'] ?? null,
                ]);
            } catch (Throwable $e) {
                $this->logger->error('Control watcher error', [
                    'error' => $e->getMessage(),
                    'command' => $command,
                ]);
                if ($runningPath !== null && is_file($runningPath)) {
                    $this->commands->finish($runningPath, 'failed', [
                        'ok' => false,
                        'stdout' => '',
                        'stderr' => $e->getMessage(),
                        'command' => is_array($command) ? $command : [],
                    ]);
                }

                usleep($pollIntervalMs * 1000);
            }
        }
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processCommand(array $command): array
    {
        return match ((string) ($command['type'] ?? '')) {
            'transport_command' => $this->processTransportCommand($command),
            default => [
                'ok' => true,
                'stdout' => '',
                'stderr' => '',
                'command' => $command,
            ],
        };
    }

    private function acquireLock(): void
    {
        $paths = new RuntimePaths($this->config);
        $lockFile = (string) $this->config->get('control_queue', 'lock_file', $paths->workerLockFile('control_watcher'));
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($lockFile, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException("Cannot open {$lockFile}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Control watcher already running');
        }

        $this->lockHandle = $handle;
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        $pidFile = (string) $this->config->get('background', 'control_watcher_pid_file', $paths->workerPidFile('control_watcher'));
        file_put_contents($pidFile, (string) getmypid());
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processTransportCommand(array $command): array
    {
        $text = trim((string) ($command['text'] ?? ''));
        $channelId = $command['channel_id'] ?? null;
        $sessionId = trim((string) ($command['session_id'] ?? ''));
        if ($channelId === null || $sessionId === '' || $text === '') {
            return [
                'ok' => false,
                'stdout' => '',
                'stderr' => 'Invalid transport command payload',
                'command' => $command,
            ];
        }

        if (preg_match('/^\/reset(?:@\S+)?(?:\s|$)/ui', $text)) {
            return $this->processResetSessionCommand($command, $channelId, $sessionId);
        }

        if (preg_match('/^\/stop(?:@\S+)?(?:\s|$)/ui', $text)) {
            return $this->processStopTransportCommand($command, $channelId, $sessionId);
        }

        if (preg_match('/^\/session(?:@\S+)?(?:\s|$)/ui', $text)) {
            return $this->processSessionCommand($command, $channelId, $sessionId, $text);
        }

        $eventId = $this->ingress->enqueueUserMessage(new TransportInboundMessage(
            channelId: $channelId,
            text: $text,
            sessionId: $sessionId,
            channelType: isset($command['channel_type']) ? (string) $command['channel_type'] : null,
            replyToMessageId: isset($command['reply_to_message_id']) ? (int) $command['reply_to_message_id'] : null,
            threadId: isset($command['thread_id']) ? (int) $command['thread_id'] : null,
            transportMessageId: $command['transport_message_id'] ?? null,
            meta: is_array($command['meta'] ?? null) ? $command['meta'] : []
        ), true);

        return [
            'ok' => true,
            'stdout' => '',
            'stderr' => '',
            'command' => $command,
            'forwarded_to_manager' => true,
            'event_id' => $eventId,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processResetSessionCommand(array $command, int|string $channelId, string $runtimeSessionId): array
    {
        $state = $this->readManagerState();
        $hadSession = array_key_exists($runtimeSessionId, $state['sessions']);
        unset($state['sessions'][$runtimeSessionId]);
        $this->stateStore->write($state);

        $this->transport->sendMessage($runtimeSessionId, 'Текущая сессия сброшена.');

        return [
            'ok' => true,
            'stdout' => '',
            'stderr' => '',
            'command' => $command,
            'channel_id' => $channelId,
            'runtime_session_id' => $runtimeSessionId,
            'session_removed' => $hadSession,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processStopTransportCommand(array $command, int|string $channelId, string $runtimeSessionId): array
    {
        $result = $this->activeTurn->requestStop();

        return [
            'ok' => true,
            'stdout' => '',
            'stderr' => '',
            'command' => $command,
            'channel_id' => $channelId,
            'runtime_session_id' => $runtimeSessionId,
            'signal' => 'SIGTERM',
            'signal_sent' => $result['signal_sent'],
            'pid' => $result['pid'],
            'active_turn' => $result['active_turn'],
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processSessionCommand(array $command, int|string $channelId, string $runtimeSessionId, string $text): array
    {
        $sessionId = $this->extractSessionId($text);
        if ($sessionId === null) {
            $this->sendAvailableSessions($runtimeSessionId);

            return [
                'ok' => true,
                'stdout' => '',
                'stderr' => '',
                'command' => $command,
                'channel_id' => $channelId,
                'runtime_session_id' => $runtimeSessionId,
                'mode' => 'list',
            ];
        }

        $state = $this->readManagerState();
        $state['sessions'][$runtimeSessionId] = $sessionId;
        $this->stateStore->write($state);

        $this->transport->sendMessage(
            $runtimeSessionId,
            "Текущая сессия установлена:\n```\n{$sessionId}\n```"
        );

        return [
            'ok' => true,
            'stdout' => '',
            'stderr' => '',
            'command' => $command,
            'channel_id' => $channelId,
            'runtime_session_id' => $runtimeSessionId,
            'mode' => 'set',
            'codex_session_id' => $sessionId,
        ];
    }

    private function sendAvailableSessions(string $runtimeSessionId): void
    {
        $homeDirectory = trim((string) (getenv('HOME') ?: '/home/web'));
        $sessions = $this->sessions->listForHomeDirectory($homeDirectory);
        if ($sessions === []) {
            $this->transport->sendMessage($runtimeSessionId, 'Для каталога ~ доступных сессий не найдено.');
            return;
        }

        $chunks = [];
        $currentChunk = '';
        foreach ($sessions as $session) {
            $title = $this->shortenSessionTitle((string) ($session['title'] ?? ''));
            $title = $title !== '' ? $title : 'Без названия';
            $updatedAt = $this->formatUpdatedAt((int) ($session['updated_at'] ?? 0));
            $entry = '**' . $this->escapeMarkdown($title) . "**\n"
                . 'Последний доступ: ' . $this->escapeMarkdown($updatedAt) . "\n"
                . "```\n" . (string) $session['id'] . "\n```";

            $candidate = $currentChunk === '' ? $entry : ($currentChunk . "\n\n" . $entry);
            if (mb_strlen($candidate) > 3200 && $currentChunk !== '') {
                $chunks[] = $currentChunk;
                $currentChunk = $entry;
                continue;
            }

            $currentChunk = $candidate;
        }

        if ($currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        foreach ($chunks as $chunk) {
            $this->transport->sendMessage($runtimeSessionId, $chunk);
        }
    }

    private function extractSessionId(string $text): ?string
    {
        $parts = preg_split('/\s+/', trim($text), 2);
        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }

        $sessionId = trim((string) $parts[1]);

        return $sessionId !== '' ? $sessionId : null;
    }

    /**
     * @return array{sessions: array<string, string>}
     */
    private function readManagerState(): array
    {
        $state = $this->stateStore->read();

        return [
            'sessions' => is_array($state['sessions'] ?? null) ? $state['sessions'] : [],
        ];
    }

    private function formatUpdatedAt(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'unknown';
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone((string) date_default_timezone_get()))
            ->format('Y-m-d H:i');
    }

    private function shortenSessionTitle(string $title, int $limit = 44): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '') {
            return '';
        }

        if (mb_strlen($title) <= $limit) {
            return $title;
        }

        return rtrim(mb_substr($title, 0, max(1, $limit - 3))) . '...';
    }

    private function escapeMarkdown(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '*' => '\*',
            '_' => '\_',
            '[' => '\[',
            ']' => '\]',
            '(' => '\(',
            ')' => '\)',
            '`' => '\`',
        ]);
    }
}
