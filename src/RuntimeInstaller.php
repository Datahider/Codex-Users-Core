<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\FileQueue\FileQueueLayout;
use RuntimeException;

final class RuntimeInstaller
{
    private readonly CodexHomeResolver $codexHomeResolver;

    public function __construct(?CodexHomeResolver $codexHomeResolver = null)
    {
        $this->codexHomeResolver = $codexHomeResolver ?? new CodexHomeResolver();
    }

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

    public function ensureBundledSkills(?string $codexHome = null, ?string $projectRoot = null): void
    {
        $projectRoot = rtrim((string) ($projectRoot ?? dirname(__DIR__)), '/');
        $sourceRoot = $projectRoot . '/skills';
        if (!is_dir($sourceRoot)) {
            return;
        }

        $resolvedCodexHome = $this->codexHomeResolver->resolve($codexHome);
        if ($resolvedCodexHome === null || $resolvedCodexHome === '') {
            throw new RuntimeException('Cannot resolve CODEX_HOME for bundled skills installation');
        }

        $targetRoot = rtrim($resolvedCodexHome, '/') . '/skills';
        $this->ensureDirectory($targetRoot);

        $entries = scandir($sourceRoot);
        if (!is_array($entries)) {
            throw new RuntimeException("Cannot read bundled skills directory: {$sourceRoot}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $sourceRoot . '/' . $entry;
            if (!is_dir($sourcePath)) {
                continue;
            }

            $targetPath = $targetRoot . '/' . $entry;
            if ($this->directoriesDiffer($sourcePath, $targetPath)) {
                $this->syncDirectory($sourcePath, $targetPath);
            }
        }
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

    private function directoriesDiffer(string $source, string $target): bool
    {
        if (!is_dir($target)) {
            return true;
        }

        $sourceFiles = $this->collectRelativeFiles($source);
        $targetFiles = $this->collectRelativeFiles($target);
        sort($sourceFiles);
        sort($targetFiles);
        if ($sourceFiles !== $targetFiles) {
            return true;
        }

        foreach ($sourceFiles as $relativePath) {
            $sourceFile = $source . '/' . $relativePath;
            $targetFile = $target . '/' . $relativePath;
            if (!is_file($targetFile)) {
                return true;
            }

            if (hash_file('sha256', $sourceFile) !== hash_file('sha256', $targetFile)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function collectRelativeFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $item->getPathname());
            $prefix = str_replace('\\', '/', rtrim($root, '/')) . '/';
            $files[] = substr($path, strlen($prefix));
        }

        return $files;
    }

    private function syncDirectory(string $source, string $target): void
    {
        if (is_dir($target)) {
            $this->deleteDirectoryContents($target);
        } else {
            $this->ensureDirectory($target);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', rtrim($source, '/')) . '/'));
            $targetPath = $target . '/' . $relativePath;

            if ($item->isDir()) {
                $this->ensureDirectory($targetPath);
                continue;
            }

            $this->ensureDirectory(dirname($targetPath));
            if (!copy($item->getPathname(), $targetPath)) {
                throw new RuntimeException("Cannot copy bundled skill file to {$targetPath}");
            }
        }
    }

    private function deleteDirectoryContents(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!rmdir($item->getPathname())) {
                    throw new RuntimeException('Cannot remove bundled skill directory: ' . $item->getPathname());
                }
                continue;
            }

            if (!unlink($item->getPathname())) {
                throw new RuntimeException('Cannot remove bundled skill file: ' . $item->getPathname());
            }
        }
    }
}
