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

        $file_exchange_url = trim((string) $config->get('file_exchange', 'base_url', ''));
        if ($file_exchange_url === '' || filter_var($file_exchange_url, FILTER_VALIDATE_URL) === false) {
            $issues[] = 'file_exchange.base_url must be a valid URL';
        }
        if (trim((string) $config->get('file_exchange', 'token', '')) === '') {
            $issues[] = 'file_exchange.token is empty';
        }

        $transcription_api_key = trim((string) $config->get('transcription', 'api_key', ''));
        if ($transcription_api_key === '') {
            $issues[] = 'transcription.api_key is empty';
        }

        $transcription_model = trim((string) $config->get('transcription', 'model', ''));
        if ($transcription_model === '') {
            $issues[] = 'transcription.model is empty';
        }

        if (trim((string) $config->get('speech', 'model', '')) === '') {
            $issues[] = 'speech.model is empty';
        }
        $default_voice = strtolower(trim((string) $config->get('voice_response', 'default_voice', '')));
        $allowed_voices = $config->get('voice_response', 'allowed_voices', []);
        if (!is_array($allowed_voices) || $allowed_voices === []) {
            $issues[] = 'voice_response.allowed_voices must be a non-empty array';
        } else {
            $normalized_voices = array_map(static fn (mixed $voice): string => strtolower(trim((string) $voice)), $allowed_voices);
            if ($default_voice === '' || !in_array($default_voice, $normalized_voices, true)) {
                $issues[] = 'voice_response.default_voice must occur in allowed_voices';
            }
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
        while (!is_dir($parent)) {
            $nextParent = dirname($parent);
            if ($nextParent === $parent) {
                return ["storage.root cannot be created: no writable existing parent for {$storageRoot}"];
            }

            $parent = $nextParent;
        }

        if (!is_writable($parent)) {
            return ["storage.root parent directory is not writable: {$parent}"];
        }

        return [];
    }
}
