<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class CodexProcess
{
    public function __construct(
        private Config $config,
        private Logger $logger,
        private ActiveTurnRegistry $activeTurn
    )
    {
    }

    public function run(
        string $prompt,
        ?string $sessionId,
        ?string $workingDir,
        callable $onProgress,
        ?string $runtimeSessionId = null
    ): array
    {
        $tmpDir = rtrim($this->config->storagePath('tmp_dir'), '/');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $debugDir = rtrim((string) $this->config->get('codex', 'debug_dir', $tmpDir . '/codex-debug'), '/');
        if (!is_dir($debugDir)) {
            mkdir($debugDir, 0775, true);
        }

        $debugBase = $debugDir . '/codex-run-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $rawStdoutPath = $debugBase . '.stdout.jsonl';
        $rawStderrPath = $debugBase . '.stderr.log';
        $outputFile = $tmpDir . '/codex-last-message-' . uniqid('', true) . '.txt';
        $command = $this->buildCommand($prompt, $sessionId, $outputFile);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->processEnvironment($sessionId, $runtimeSessionId);
        $resolvedWorkingDir = $workingDir ?: $this->config->get('codex', 'cwd');
        $startupWarning = null;
        set_error_handler(static function (int $severity, string $message) use (&$startupWarning): bool {
            $startupWarning = $message;

            return true;
        });
        try {
            $process = proc_open(
                $command,
                $descriptorSpec,
                $pipes,
                $resolvedWorkingDir,
                $env
            );
        } finally {
            restore_error_handler();
        }
        if (!is_resource($process)) {
            $details = [
                'command' => $command,
                'cwd' => $resolvedWorkingDir,
                'codex_session_id' => $sessionId,
                'runtime_session_id' => $runtimeSessionId,
                'startup_warning' => $startupWarning,
            ];
            $this->logger->error('Cannot start codex process', $details);

            $message = 'Cannot start codex process';
            if ($startupWarning !== null && trim($startupWarning) !== '') {
                $message .= ': ' . trim($startupWarning);
            }

            throw new RuntimeException($message);
        }

        $status = proc_get_status($process);
        $pid = isset($status['pid']) && is_numeric($status['pid']) ? (int) $status['pid'] : null;
        $this->activeTurn->begin([
            'pid' => $pid,
            'runtime_session_id' => $runtimeSessionId,
            'codex_session_id' => $sessionId,
            'worker_pid' => getmypid(),
            'working_dir' => $resolvedWorkingDir,
            'command' => $command,
        ]);

        try {
            fwrite($pipes[0], $prompt);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $buffer = '';
            $stderr = '';
            $streamedText = '';
            $detectedSessionId = $sessionId;
            $pendingAgentMessage = null;
            $finalAgentMessage = null;
            $heartbeatIntervalMs = (int) $this->config->get(
                'transport',
                'progress_keepalive_ms',
                4000
            );
            $nextHeartbeatAt = microtime(true) + max(1, $heartbeatIntervalMs) / 1000;

            while (true) {
                $status = proc_get_status($process);
                $now = microtime(true);

                $stdoutChunk = stream_get_contents($pipes[1]);
                if ($stdoutChunk !== false && $stdoutChunk !== '') {
                    file_put_contents($rawStdoutPath, $stdoutChunk, FILE_APPEND | LOCK_EX);
                    $buffer .= $stdoutChunk;
                    $deltas = [];
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

                        $detectedSessionId = $this->extractSessionId($event) ?? $detectedSessionId;
                        $delta = $this->extractDeltaText($event);
                        $commentary = $this->extractCompletedAgentCommentary($event, $pendingAgentMessage, $finalAgentMessage);
                        if ($commentary !== '') {
                            $delta = $delta === '' ? $commentary : ($commentary . "\n\n" . $delta);
                        }

                        if ($delta !== '') {
                            $deltas[] = $delta;
                        }
                    }

                    $deltasCount = count($deltas);
                    foreach ($deltas as $index => $delta) {
                        $streamedText = $streamedText === '' ? $delta : ($streamedText . "\n\n" . $delta);
                        $isLastDeltaInFinalBurst = !$status['running'] && $index === ($deltasCount - 1);
                        if ($isLastDeltaInFinalBurst) {
                            continue;
                        }

                        $onProgress($streamedText, $delta, (bool) $status['running']);
                        $nextHeartbeatAt = $now + max(1, $heartbeatIntervalMs) / 1000;
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
                    $onProgress($streamedText, '', (bool) $status['running']);
                    $nextHeartbeatAt = $now + max(1, $heartbeatIntervalMs) / 1000;
                }

                usleep(200000);
            }

            $this->safeClose($pipes[1] ?? null);
            $this->safeClose($pipes[2] ?? null);
            $exitCode = proc_close($process);

            if ($finalAgentMessage === null && $pendingAgentMessage !== null && trim($pendingAgentMessage) !== '') {
                $finalAgentMessage = trim($pendingAgentMessage);
            }

            $finalText = '';
            if (is_file($outputFile)) {
                $finalText = trim((string) file_get_contents($outputFile));
                @unlink($outputFile);
            }

            if ($finalText === '') {
                $finalText = trim((string) ($finalAgentMessage ?? $streamedText));
            }

            if ($finalText === '' && $stderr !== '') {
                $finalText = "Codex вернул ошибку:\n" . trim($stderr);
            }

            $this->logger->info('Codex process finished', [
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
            if (is_file($outputFile)) {
                @unlink($outputFile);
            }
            $this->activeTurn->clear();
        }
    }

    private function buildCommand(string $prompt, ?string $sessionId, string $outputFile): array
    {
        $command = [
            (string) $this->config->require('codex', 'bin'),
            'exec',
        ];

        foreach ((array) $this->config->get('codex', 'extra_args', []) as $arg) {
            $command[] = (string) $arg;
        }

        if ($sessionId !== null && $sessionId !== '') {
            $command[] = 'resume';
            $command[] = $sessionId;
        }

        $command[] = '-o';
        $command[] = $outputFile;
        $command[] = '-';

        return $command;
    }

    private function extractSessionId(array $event): ?string
    {
        foreach (['session_id', 'thread_id', 'conversation_id'] as $key) {
            if (!empty($event[$key]) && is_string($event[$key])) {
                return $event[$key];
            }
        }

        return null;
    }

    private function extractDeltaText(array $event): string
    {
        $type = (string) ($event['type'] ?? '');
        if (!str_contains($type, 'delta') && !str_contains($type, 'message')) {
            return '';
        }

        $strings = [];
        $this->collectStrings($event, $strings);
        if ($strings === []) {
            return '';
        }

        foreach ($strings as $string) {
            if ($string === $type) {
                continue;
            }
            if (preg_match('/^(turn|item|response|thread)\./', $string)) {
                continue;
            }

            return $string;
        }

        return '';
    }

    private function extractCompletedAgentCommentary(array $event, ?string &$pendingAgentMessage, ?string &$finalAgentMessage): string
    {
        $type = (string) ($event['type'] ?? '');
        if ($type === 'item.completed') {
            $item = $event['item'] ?? null;
            if (!is_array($item)) {
                return '';
            }

            $itemType = (string) ($item['type'] ?? '');
            if ($itemType === 'agent_message') {
                $text = trim((string) ($item['text'] ?? ''));
                if ($text === '') {
                    return '';
                }

                if ($pendingAgentMessage !== null) {
                    $commentary = $pendingAgentMessage;
                    $pendingAgentMessage = $text;

                    return $commentary;
                }

                $pendingAgentMessage = $text;

                return '';
            }
        }

        if ($type === 'item.started' && $pendingAgentMessage !== null) {
            $commentary = $pendingAgentMessage;
            $pendingAgentMessage = null;

            return $commentary;
        }

        if ($type === 'turn.completed' && $pendingAgentMessage !== null) {
            $finalAgentMessage = $pendingAgentMessage;
            $pendingAgentMessage = null;
        }

        return '';
    }

    private function collectStrings(mixed $value, array &$strings): void
    {
        if (is_string($value)) {
            $strings[] = $value;
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->collectStrings($item, $strings);
        }
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
    private function processEnvironment(?string $codexSessionId, ?string $runtimeSessionId): array
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

        $shimBin = dirname(__DIR__) . '/bin/shims';
        $path = (string) ($env['PATH'] ?? getenv('PATH') ?: '');
        if (is_dir($shimBin) && !str_starts_with($path, $shimBin . ':')) {
            $env['PATH'] = $shimBin . ($path !== '' ? ':' . $path : '');
        }

        if ($codexSessionId !== null && $codexSessionId !== '') {
            $env['CODEX_SID'] = $codexSessionId;
        }

        if ($runtimeSessionId !== null && $runtimeSessionId !== '') {
            $env['RUNTIME_SID'] = $runtimeSessionId;
        }

        return $env;
    }
}
