<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class ClaudeRateLimitsStore
{
    private const WINDOWS = ['five_hour' => 'primary', 'seven_day' => 'secondary'];

    public function __construct(private string $path)
    {
    }

    public static function forConfig(Config $config): self
    {
        return new self((new RuntimePaths($config))->stateDir() . '/claude-rate-limits.json');
    }

    /**
     * Окна из rate_limit_info события rate_limit_event в формате лимитов Codex.
     *
     * @param array<string, mixed> $event
     * @return array<string, array{usedPercent: int, resetsAt: int}>
     */
    public static function parseEvent(array $event): array
    {
        $windows = $event['rate_limit_info']['unifiedWindows'] ?? null;
        if (!is_array($windows)) {
            throw new RuntimeException('rate_limit_event has no unifiedWindows');
        }

        $limits = [];
        foreach (self::WINDOWS as $source => $target) {
            if (!array_key_exists($source, $windows)) {
                continue;
            }
            $window = $windows[$source];
            if (!is_array($window) || !is_numeric($window['utilization'] ?? null) || !is_int($window['resetsAt'] ?? null)) {
                throw new RuntimeException("Invalid rate_limit_event window: {$source}");
            }
            $limits[$target] = [
                'usedPercent' => (int) round((float) $window['utilization'] * 100),
                'resetsAt' => $window['resetsAt'],
            ];
        }

        return $limits;
    }

    /** @param array<string, array{usedPercent: int, resetsAt: int}> $limits */
    public function save(array $limits, int $observed_at): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }

        $payload = json_encode(['observed_at' => $observed_at] + $limits, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $payload, LOCK_EX) === false || !rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write {$this->path}");
        }
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read {$this->path}");
        }
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_int($data['observed_at'] ?? null)) {
            throw new RuntimeException("Invalid Claude rate limits file: {$this->path}");
        }

        return $data;
    }
}
