<?php

declare(strict_types=1);

namespace CodexRuntime\ScheduledQueue;

use CodexRuntime\Config;
use CodexRuntime\FileQueue\FileQueueLayout;
use RuntimeException;

final class ScheduledJobRepository
{
    private readonly FileQueueLayout $layout;

    public function __construct(private Config $config)
    {
        $this->layout = new FileQueueLayout($config);
    }

    /**
     * @return list<string>
     */
    public function duePaths(string $nowPrefix): array
    {
        $dir = $this->layout->scheduledDir();
        $files = glob($dir . '/*.json');
        if ($files === false || $files === []) {
            return [];
        }

        sort($files, SORT_STRING);

        $due = [];
        foreach ($files as $path) {
            $basename = basename($path, '.json');
            $prefix = $this->extractPrefix($basename);
            if ($prefix === null) {
                continue;
            }

            if (strcmp($prefix, $nowPrefix) <= 0) {
                $due[] = $path;
            }
        }

        return $due;
    }

    public function load(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read scheduled job {$path}");
        }

        $job = json_decode($raw, true);
        if (!is_array($job)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        return $job;
    }

    public function delete(string $path): void
    {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException("Cannot delete scheduled job {$path}");
        }
    }

    public function targetExists(string $targetQueue, string $id): bool
    {
        $section = match ($targetQueue) {
            'command' => 'command_watcher',
            'manager' => 'manager_queue',
            default => throw new RuntimeException("Unknown target queue {$targetQueue}"),
        };

        foreach (['new', 'running', 'done', 'failed'] as $queue) {
            if (is_file($this->layout->queuePath($targetQueue, $queue, $id))) {
                return true;
            }
        }

        $resultsDir = $this->layout->resultsDir($targetQueue);

        return is_file($resultsDir . '/' . $id . '.result.json');
    }

    private function extractPrefix(string $basename): ?string
    {
        if (preg_match('/^(\d{8}-\d{6})-/', $basename, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
