<?php

declare(strict_types=1);

namespace CodexRuntime\FileQueue;

use CodexRuntime\Config;
use CodexRuntime\RuntimePaths;
use RuntimeException;

final class FileQueueLayout
{
    private readonly string $root;

    public function __construct(Config $config)
    {
        $this->root = (new RuntimePaths($config))->root();
    }

    public function queuePath(string $queueName, string $state, string $id): string
    {
        return $this->queueDir($queueName, $state) . '/' . $id . '.json';
    }

    public function queueDir(string $queueName, string $state): string
    {
        $dir = $this->root . '/' . $this->queueDirectoryName($queueName) . '/' . $state;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public function resultsDir(string $queueName): string
    {
        $dir = $this->root . '/' . $this->resultsDirectoryName($queueName);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public function scheduledDir(): string
    {
        $dir = $this->root . '/scheduled-queue';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function queueDirectoryName(string $queueName): string
    {
        return match ($queueName) {
            'manager' => 'manager-queue',
            'outbound' => 'outbound-queue',
            'command' => 'command-queue',
            'exec' => 'exec-queue',
            'control' => 'control-queue',
            default => throw new RuntimeException("Unknown file queue {$queueName}"),
        };
    }

    private function resultsDirectoryName(string $queueName): string
    {
        return match ($queueName) {
            'manager' => 'manager-results',
            'command' => 'command-results',
            'exec' => 'exec-results',
            'control' => 'control-results',
            default => throw new RuntimeException("Queue {$queueName} does not define results storage"),
        };
    }
}
