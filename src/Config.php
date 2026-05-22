<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class Config
{
    public function __construct(private array $data)
    {
    }

    public static function fromFile(string $path): self
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            throw new RuntimeException("Config file not found: {$path}");
        }

        $data = self::loadArray($realPath);
        $basePath = dirname($realPath) . '/config.php';

        if (basename($realPath) !== 'config.php' && is_file($basePath)) {
            $data = array_replace_recursive(self::loadArray($basePath), $data);
        }

        return new self($data);
    }

    public function get(string $section, ?string $key = null, mixed $default = null): mixed
    {
        if (!array_key_exists($section, $this->data)) {
            return $default;
        }

        if ($key === null) {
            return $this->data[$section];
        }

        return $this->data[$section][$key] ?? $default;
    }

    public function require(string $section, string $key): mixed
    {
        $value = $this->get($section, $key);
        if ($value === null || $value === '') {
            throw new RuntimeException("Missing config value {$section}.{$key}");
        }

        return $value;
    }

    public function storagePath(string $key): string
    {
        return (string) $this->require('storage', $key);
    }

    /**
     * @return list<int|string>
     */
    public function requireList(string $section, string $key): array
    {
        $value = $this->require($section, $key);
        if (!is_array($value)) {
            throw new RuntimeException("Config value {$section}.{$key} must be an array");
        }

        return array_values($value);
    }

    private static function loadArray(string $path): array
    {
        $data = require $path;
        if (!is_array($data)) {
            throw new RuntimeException("Config file must return an array: {$path}");
        }

        return $data;
    }
}
