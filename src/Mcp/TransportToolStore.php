<?php

declare(strict_types=1);

namespace CodexRuntime\Mcp;

use CodexRuntime\Logger;
use RuntimeException;

final class TransportToolStore
{
    public function __construct(private string $root, private Logger $logger)
    {
        foreach ([$this->pendingDir(), $this->responseDir(), $this->cacheDir(), $this->lockDir()] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create transport tool directory {$dir}");
            }
        }
    }

    public function register(string $runtime_session_id, string $operation, float $timeout_seconds): string
    {
        $this->cleanupExpired();
        $request_id = bin2hex(random_bytes(16));
        $this->writeJson($this->pendingPath($request_id), [
            'runtime_session_id' => $runtime_session_id,
            'operation' => $operation,
            'expires_at' => microtime(true) + $timeout_seconds,
        ]);
        return $request_id;
    }

    /** @param array<string, mixed> $meta */
    public function acceptResponse(string $runtime_session_id, array $meta): bool
    {
        $request_id = trim((string) ($meta['request_id'] ?? ''));
        if (preg_match('/^[a-f0-9]{32}$/D', $request_id) !== 1
            || !in_array((string) ($meta['type'] ?? ''), ['list', 'result', 'error'], true)) {
            $this->logger->error('Discarded invalid transport tool response', ['runtime_session_id' => $runtime_session_id]);
            return false;
        }
        $pending = $this->readJson($this->pendingPath($request_id));
        if ($pending === null || (string) ($pending['runtime_session_id'] ?? '') !== $runtime_session_id) {
            $this->logger->error('Discarded unknown or foreign transport tool response', [
                'runtime_session_id' => $runtime_session_id,
                'request_id' => $request_id,
            ]);
            return false;
        }
        if ((float) ($pending['expires_at'] ?? 0) < microtime(true)) {
            @unlink($this->pendingPath($request_id));
            @unlink($this->responsePath($request_id));
            $this->logger->error('Discarded late transport tool response', ['request_id' => $request_id]);
            return false;
        }
        $type = (string) $meta['type'];
        $operation = (string) ($pending['operation'] ?? '');
        $valid_payload = ($operation === 'list' && $type === 'list' && is_array($meta['tools'] ?? null))
            || ($operation === 'call' && $type === 'result' && is_array($meta['result'] ?? null))
            || ($operation === 'call'
                && $type === 'error'
                && is_array($meta['error'] ?? null)
                && is_string($meta['error']['code'] ?? null)
                && is_string($meta['error']['message'] ?? null));
        if (!$valid_payload) {
            $this->logger->error('Discarded invalid transport tool response payload', [
                'request_id' => $request_id,
                'operation' => $operation,
                'type' => $type,
            ]);
            return false;
        }
        if (is_file($this->responsePath($request_id))) {
            $this->logger->error('Discarded duplicate transport tool response', ['request_id' => $request_id]);
            return false;
        }
        $this->writeJson($this->responsePath($request_id), $meta);
        return true;
    }

    /** @return array<string, mixed>|null */
    public function await(string $request_id, float $timeout_seconds): ?array
    {
        $deadline = microtime(true) + $timeout_seconds;
        do {
            $response = $this->readJson($this->responsePath($request_id));
            if ($response !== null) {
                @unlink($this->responsePath($request_id));
                @unlink($this->pendingPath($request_id));
                return $response;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        @unlink($this->pendingPath($request_id));
        @unlink($this->responsePath($request_id));
        return null;
    }

    /** @return array{tools:array<int, array<string, mixed>>,expires_at:float}|null */
    public function readCache(string $runtime_session_id): ?array
    {
        $cache = $this->readJson($this->cachePath($runtime_session_id));
        if ($cache === null || !is_array($cache['tools'] ?? null) || (float) ($cache['expires_at'] ?? 0) <= microtime(true)) {
            return null;
        }
        return ['tools' => array_values($cache['tools']), 'expires_at' => (float) $cache['expires_at']];
    }

    /** @param array<int, array<string, mixed>> $tools */
    public function writeCache(string $runtime_session_id, array $tools, float $ttl_seconds): void
    {
        $this->writeJson($this->cachePath($runtime_session_id), [
            'tools' => array_values($tools),
            'expires_at' => microtime(true) + $ttl_seconds,
        ]);
    }

    /** @return array{handle:resource,leader:bool} */
    public function acquireListLock(string $runtime_session_id)
    {
        $path = $this->lockDir() . '/' . hash('sha256', $runtime_session_id) . '.lock';
        $handle = fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot acquire transport tool list lock');
        }
        $leader = flock($handle, LOCK_EX | LOCK_NB);
        if (!$leader && !flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Cannot acquire transport tool list lock');
        }
        return ['handle' => $handle, 'leader' => $leader];
    }

    /** @param resource $handle */
    public function releaseListLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function cleanupExpired(): void
    {
        foreach (glob($this->pendingDir() . '/*.json') ?: [] as $path) {
            $pending = $this->readJson($path);
            if ($pending === null || (float) ($pending['expires_at'] ?? 0) < microtime(true)) {
                $request_id = basename($path, '.json');
                @unlink($path);
                @unlink($this->responsePath($request_id));
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Cannot write transport tool state {$path}");
        }
    }

    private function pendingDir(): string { return $this->root . '/pending'; }
    private function responseDir(): string { return $this->root . '/responses'; }
    private function cacheDir(): string { return $this->root . '/cache'; }
    private function lockDir(): string { return $this->root . '/locks'; }
    private function pendingPath(string $id): string { return $this->pendingDir() . '/' . $id . '.json'; }
    private function responsePath(string $id): string { return $this->responseDir() . '/' . $id . '.json'; }
    private function cachePath(string $sid): string { return $this->cacheDir() . '/' . hash('sha256', $sid) . '.json'; }
}
