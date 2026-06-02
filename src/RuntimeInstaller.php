<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\FileQueue\FileQueueLayout;
use RuntimeException;

final class RuntimeInstaller
{
    public function ensureEnvironment(): void
    {
        if (PHP_VERSION_ID < 80100) {
            throw new RuntimeException('PHP 8.1 or newer is required');
        }

        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP extension curl is required');
        }

        foreach (['codex', 'logger'] as $command) {
            if (Environment::resolveCommand($command) === null) {
                throw new RuntimeException("Required command is not available in PATH: {$command}");
            }
        }
    }

    public function ensureStorageLayout(Config $config): void
    {
        $paths = new RuntimePaths($config);
        foreach ([
            $paths->root(),
            $paths->runDir(),
            $paths->stateDir(),
            $paths->logDir(),
            $paths->tmpDir(),
            $paths->codexDebugDir(),
        ] as $dir) {
            $this->ensureDirectory($dir);
        }

        $layout = new FileQueueLayout($config);
        foreach (['manager', 'command', 'exec', 'control'] as $queueName) {
            foreach (['new', 'running', 'done', 'failed'] as $state) {
                $layout->queueDir($queueName, $state);
            }
        }

        foreach (['manager', 'command', 'exec', 'control'] as $queueName) {
            $layout->resultsDir($queueName);
        }

        $layout->scheduledDir();
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            if (!is_writable($path)) {
                throw new RuntimeException("Directory is not writable: {$path}");
            }

            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException("Cannot create directory: {$path}");
        }
    }
}
