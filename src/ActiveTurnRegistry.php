<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class ActiveTurnRegistry
{
    public function __construct(private string $path)
    {
    }

    /**
     * @param array<string, mixed> $turn
     */
    public function begin(array $turn): void
    {
        $runtime_session_id = trim((string) ($turn['runtime_session_id'] ?? ''));
        if ($runtime_session_id === '') {
            throw new RuntimeException('Active turn runtime_session_id must not be empty');
        }
        $turn['started_at'] ??= date(DATE_ATOM);
        $this->write($runtime_session_id, $turn);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(string $runtime_session_id): ?array
    {
        $path = $this->sessionPath($runtime_session_id);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        return $data;
    }

    /**
     * @return array{signal_sent: bool, pid: ?int, active_turn: ?array<string, mixed>}
     */
    public function requestStop(string $runtime_session_id): array
    {
        $activeTurn = $this->current($runtime_session_id);
        if ($activeTurn === null) {
            return [
                'signal_sent' => false,
                'pid' => null,
                'active_turn' => null,
            ];
        }

        $pid = isset($activeTurn['pid']) && is_numeric($activeTurn['pid']) ? (int) $activeTurn['pid'] : null;
        $signalSent = false;
        if ($pid !== null && $pid > 1) {
            $signalSent = $this->signalPid($pid);
        }

        $activeTurn['stop_requested_at'] = date(DATE_ATOM);
        $activeTurn['stop_signal'] = 'SIGTERM';
        $this->write($runtime_session_id, $activeTurn);

        return [
            'signal_sent' => $signalSent,
            'pid' => $pid,
            'active_turn' => $activeTurn,
        ];
    }

    public function clear(string $runtime_session_id): void
    {
        $path = $this->sessionPath($runtime_session_id);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $runtime_session_id, array $data): void
    {
        $path = $this->sessionPath($runtime_session_id);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("Cannot encode active turn JSON for {$path}");
        }

        if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write {$path}");
        }
    }

    private function sessionPath(string $runtime_session_id): string
    {
        $runtime_session_id = trim($runtime_session_id);
        if ($runtime_session_id === '') {
            throw new RuntimeException('Active turn runtime_session_id must not be empty');
        }

        return $this->path . '.d/' . hash('sha256', $runtime_session_id) . '.json';
    }

    private function signalPid(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, SIGTERM);
        }

        exec('kill -TERM ' . escapeshellarg((string) $pid), $output, $code);

        return $code === 0;
    }
}
