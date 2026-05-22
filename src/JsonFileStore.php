<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class JsonFileStore
{
    public function __construct(private string $path)
    {
    }

    public function read(): array
    {
        if (!is_file($this->path)) {
            return [
                'offset' => 0,
                'channels' => [],
                'chats' => [],
            ];
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [
                'offset' => 0,
                'channels' => [],
                'chats' => [],
            ];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON in {$this->path}");
        }

        $data['offset'] ??= 0;
        $data['channels'] ??= [];
        $data['chats'] ??= [];

        return $data;
    }

    public function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Cannot encode state json');
        }

        if (file_put_contents($this->path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write {$this->path}");
        }
    }
}
