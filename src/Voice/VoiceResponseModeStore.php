<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use RuntimeException;

final class VoiceResponseModeStore
{
    public function __construct(private string $path)
    {
    }

    public function set(string $runtime_session_id, string $mode, string $scope): void
    {
        $runtime_session_id = trim($runtime_session_id);
        $mode = strtolower(trim($mode));
        $scope = strtolower(trim($scope));
        if ($runtime_session_id === '') {
            throw new RuntimeException('Runtime session id is required');
        }
        if (!in_array($mode, ['text', 'voice'], true)) {
            throw new RuntimeException('Invalid response mode');
        }
        if (!in_array($scope, ['once', 'persistent'], true)) {
            throw new RuntimeException('Invalid response mode scope');
        }

        $state = $this->read();
        $state[$scope][$runtime_session_id] = $mode;
        if ($scope === 'persistent') {
            unset($state['once'][$runtime_session_id]);
        }
        $this->write($state);
    }

    public function consume(string $runtime_session_id): string
    {
        $state = $this->read();
        if (isset($state['once'][$runtime_session_id])) {
            $mode = $state['once'][$runtime_session_id];
            unset($state['once'][$runtime_session_id]);
            $this->write($state);

            return $mode;
        }

        return $state['persistent'][$runtime_session_id] ?? 'text';
    }

    /** @return array{persistent: array<string, string>, once: array<string, string>} */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return ['persistent' => [], 'once' => []];
        }
        $decoded = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['persistent'] ?? null) || !is_array($decoded['once'] ?? null)) {
            throw new RuntimeException('Invalid voice response mode state');
        }

        return ['persistent' => $decoded['persistent'], 'once' => $decoded['once']];
    }

    /** @param array{persistent: array<string, string>, once: array<string, string>} $state */
    private function write(array $state): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create voice response mode state directory');
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot write voice response mode state');
        }
    }
}
