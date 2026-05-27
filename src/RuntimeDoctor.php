<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class RuntimeDoctor
{
    /**
     * @return list<string>
     */
    public function diagnose(string $configPath): array
    {
        $issues = [];

        if (!is_file($configPath) || !is_readable($configPath)) {
            return ["Config file is missing or unreadable: {$configPath}"];
        }

        try {
            $config = Config::fromFile($configPath);
        } catch (\Throwable $e) {
            return ["Config file cannot be loaded: {$e->getMessage()}"];
        }

        if (PHP_VERSION_ID < 80100) {
            $issues[] = 'PHP 8.1 or newer is required';
        }

        if (!extension_loaded('curl')) {
            $issues[] = 'PHP extension curl is required';
        }

        $routerUrl = trim((string) $config->get('router', 'base_url', ''));
        if ($routerUrl === '' || str_contains($routerUrl, 'example')) {
            $issues[] = 'router.base_url is not configured';
        } elseif (filter_var($routerUrl, FILTER_VALIDATE_URL) === false) {
            $issues[] = 'router.base_url must be a valid URL';
        }

        $routerToken = trim((string) $config->get('router', 'core_token', ''));
        if ($routerToken === '') {
            $issues[] = 'router.core_token is empty';
        }

        $codexCwd = trim((string) $config->get('codex', 'cwd', ''));
        if ($codexCwd === '') {
            $issues[] = 'codex.cwd is empty';
        } elseif (!is_dir($codexCwd)) {
            $issues[] = "codex.cwd does not exist: {$codexCwd}";
        }

        $storageRoot = trim((string) $config->get('storage', 'root', ''));
        if ($storageRoot === '') {
            $issues[] = 'storage.root is empty';
        } else {
            $issues = [...$issues, ...$this->diagnoseStorageRoot($storageRoot)];
        }

        $codexBin = trim((string) $config->get('codex', 'bin', 'codex'));
        if (Environment::resolveCommand($codexBin) === null) {
            $issues[] = "codex binary is not available in PATH: {$codexBin}";
        }

        if (Environment::resolveCommand('logger') === null) {
            $issues[] = 'logger command is not available in PATH';
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function diagnoseStorageRoot(string $storageRoot): array
    {
        if (is_dir($storageRoot)) {
            return is_writable($storageRoot)
                ? []
                : ["storage.root is not writable: {$storageRoot}"];
        }

        $parent = dirname($storageRoot);
        if (!is_dir($parent)) {
            return ["storage.root parent directory does not exist: {$parent}"];
        }

        if (!is_writable($parent)) {
            return ["storage.root parent directory is not writable: {$parent}"];
        }

        return [];
    }
}
