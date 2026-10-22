<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class ClaudeProcess
{
    public function __construct(
        private Config $config,
        private Logger $logger,
        private ActiveTurnRegistry $activeTurn,
        private ?string $config_path = null
    )
    {
    }

    public function run(
        string $prompt,
        ?string $sessionId,
        ?string $workingDir,
        callable $onProgress,
        ?string $runtimeSessionId = null,
        ?string $model = null
    ): array
    {
        $paths = new RuntimePaths($this->config);
        $debugDir = rtrim((string) $this->config->get('codex', 'debug_dir', $paths->codexDebugDir()), '/');
        if (!is_dir($debugDir)) {
            mkdir($debugDir, 0775, true);
        }

        $debugBase = $debugDir . '/claude-run-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $rawStdoutPath = $debugBase . '.stdout.jsonl';
        $rawStderrPath = $debugBase . '.stderr.log';
        $command = $this->buildCommand($sessionId, $runtimeSessionId, $model);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->processEnvironment($sessionId, $runtimeSessionId);
        $resolvedWorkingDir = $workingDir ?: (new ProviderAvailability($this->config))->cwd(ProviderAvailability::CLAUDE);
        $startupWarning = null;
        set_error_handler(static function (int $severity, string $message) use (&$startupWarning): bool {
            $startupWarning = $message;

            return true;
        });
        try {
            $process = proc_open($command, $descriptorSpec, $pipes, $resolvedWorkingDir, $env);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($process)) {
            $this->logger->error('Cannot start claude process', [
                'command' => $command,
                'cwd' => $resolvedWorkingDir,
                'claude_session_id' => $sessionId,
                'runtime_session_id' => $runtimeSessionId,
                'startup_warning' => $startupWarning,
            ]);

            $message = 'Cannot start claude process';
            if ($startupWarning !== null && trim($startupWarning) !== '') {
                $message .= ': ' . trim($startupWarning);
            }

            throw new RuntimeException($message);
        }

        $status = proc_get_status($process);
        $pid = isset($status['pid']) && is_numeric($status['pid']) ? (int) $status['pid'] : null;
        if ($runtimeSessionId !== null && trim($runtimeSessionId) !== '') {
            $this->activeTurn->begin([
                'pid' => $pid,
                'runtime_session_id' => $runtimeSessionId,
                'codex_session_id' => $sessionId,
                'worker_pid' => getmypid(),
                'working_dir' => $resolvedWorkingDir,
                'command' => $command,
            ]);
        }

        try {
            fwrite($pipes[0], $prompt);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $buffer = '';
            $stderr = '';
            $streamedText = '';
            $detectedSessionId = $sessionId;
            $pendingText = null;
            $resultEvent = null;
            $heartbeatIntervalMs = (int) $this->config->get('transport', 'progress_keepalive_ms', 4000);
            $nextHeartbeatAt = microtime(true) + max(1, $heartbeatIntervalMs) / 1000;

            while (true) {
                $status = proc_get_status($process);
                $now = microtime(true);

                $stdoutChunk = stream_get_contents($pipes[1]);
                if ($stdoutChunk !== false && $stdoutChunk !== '') {
                    file_put_contents($rawStdoutPath, $stdoutChunk, FILE_APPEND | LOCK_EX);
                    $buffer .= $stdoutChunk;
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = trim(substr($buffer, 0, $pos));
                        $buffer = substr($buffer, $pos + 1);
                        if ($line === '') {
                            continue;
                        }

                        $event = json_decode($line, true);
                        if (!is_array($event)) {
                            continue;
                        }

                        if (is_string($event['session_id'] ?? null) && $event['session_id'] !== '') {
                            $detectedSessionId = $event['session_id'];
                        }

                        $commentary = $this->consumeEvent($event, $pendingText, $resultEvent);
                        if ($commentary !== '') {
                            $streamedText = $streamedText === '' ? $commentary : ($streamedText . "\n\n" . $commentary);
                            $onProgress($streamedText, $commentary, true);
                            $nextHeartbeatAt = $now + max(1, $heartbeatIntervalMs) / 1000;
                        }
                    }
                }

                $stderrChunk = stream_get_contents($pipes[2]);
                if ($stderrChunk !== false && $stderrChunk !== '') {
                    $stderr .= $stderrChunk;
                    file_put_contents($rawStderrPath, $stderrChunk, FILE_APPEND | LOCK_EX);
                }

                if (!$status['running']) {
                    break;
                }

                if ($now >= $nextHeartbeatAt) {
                    $onProgress($streamedText, '', true);
                    $nextHeartbeatAt = $now + max(1, $heartbeatIntervalMs) / 1000;
                }

                usleep(200000);
            }

            $this->safeClose($pipes[1] ?? null);
            $this->safeClose($pipes[2] ?? null);
            $exitCode = proc_close($process);
            if ($exitCode === -1 && isset($status['exitcode']) && is_int($status['exitcode'])) {
                $exitCode = $status['exitcode'];
            }

            $finalText = trim((string) ($resultEvent['result'] ?? ''));
            $failed = $resultEvent === null || !empty($resultEvent['is_error']);
            if ($failed && $exitCode === 0) {
                $exitCode = 1;
            }

            if ($finalText === '' && trim($stderr) !== '') {
                $finalText = "Claude вернул ошибку:\n" . trim($stderr);
            }

            $this->logger->info('Claude process finished', [
                'exit_code' => $exitCode,
                'session_id' => $detectedSessionId,
                'stdout_path' => $rawStdoutPath,
                'stderr_path' => $rawStderrPath,
            ]);

            return [
                'session_id' => $detectedSessionId,
                'text' => $finalText,
                'stderr' => trim($stderr),
                'exit_code' => $exitCode,
            ];
        } finally {
            $this->safeClose($pipes[0] ?? null);
            $this->safeClose($pipes[1] ?? null);
            $this->safeClose($pipes[2] ?? null);
            if ($runtimeSessionId !== null && trim($runtimeSessionId) !== '') {
                $this->activeTurn->clear($runtimeSessionId);
            }
        }
    }

    /** @return list<string> */
    private function buildCommand(?string $sessionId, ?string $runtimeSessionId, ?string $model): array
    {
        $command = [
            (new ProviderAvailability($this->config))->bin(ProviderAvailability::CLAUDE),
            '-p',
            '--output-format',
            'stream-json',
            '--verbose',
            '--dangerously-skip-permissions',
        ];

        foreach ((array) $this->config->get('claude', 'extra_args', []) as $arg) {
            $command[] = (string) $arg;
        }

        $effective_model = trim((string) ($model ?? $this->config->get('claude', 'model', '')));
        if ($effective_model === '') {
            throw new RuntimeException('Effective Claude model is empty');
        }
        $command[] = '--model';
        $command[] = $effective_model;

        if ($this->config_path !== null && trim($this->config_path) !== '') {
            $command[] = '--mcp-config';
            $command[] = json_encode([
                'mcpServers' => [
                    'codex_runtime' => [
                        'command' => 'php',
                        'args' => [dirname(__DIR__) . '/bin/mcp-server.php'],
                        'env' => [
                            'RUNTIME_SID' => (string) $runtimeSessionId,
                            'CODEX_CORE_CONFIG' => $this->config_path,
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        if ($sessionId !== null && $sessionId !== '') {
            $command[] = '--resume';
            $command[] = $sessionId;
        }

        return $command;
    }

    /**
     * Текст assistant становится commentary, только если после него turn продолжился.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed>|null $resultEvent
     */
    private function consumeEvent(array $event, ?string &$pendingText, ?array &$resultEvent): string
    {
        $type = (string) ($event['type'] ?? '');
        if ($type === 'result') {
            $resultEvent = $event;
            $pendingText = null;

            return '';
        }

        $commentary = $pendingText ?? '';
        $pendingText = null;

        if ($type === 'assistant') {
            $texts = [];
            foreach ((array) ($event['message']['content'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text') {
                    $text = trim((string) ($block['text'] ?? ''));
                    if ($text !== '') {
                        $texts[] = $text;
                    }
                }
            }
            if ($texts !== []) {
                $pendingText = implode("\n\n", $texts);
            }
        }

        return $commentary;
    }

    private function safeClose(mixed $resource): void
    {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }

    /**
     * @return array<string, string>
     */
    private function processEnvironment(?string $claudeSessionId, ?string $runtimeSessionId): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }
        unset($env['CODEX_SID'], $env['CLAUDE_SID']);

        $shimBin = dirname(__DIR__) . '/bin/shims';
        $path = (string) ($env['PATH'] ?? getenv('PATH') ?: '');
        if (is_dir($shimBin) && !str_starts_with($path, $shimBin . ':')) {
            $env['PATH'] = $shimBin . ($path !== '' ? ':' . $path : '');
        }

        if ($claudeSessionId !== null && $claudeSessionId !== '') {
            $env['CLAUDE_SID'] = $claudeSessionId;
        }

        if ($runtimeSessionId !== null && $runtimeSessionId !== '') {
            $env['RUNTIME_SID'] = $runtimeSessionId;
        }

        if ($this->config_path !== null && trim($this->config_path) !== '') {
            $env['CODEX_CORE_CONFIG'] = $this->config_path;
        }

        $env['CODEX_STORAGE_ROOT'] = (new RuntimePaths($this->config))->root();

        return $env;
    }
}
