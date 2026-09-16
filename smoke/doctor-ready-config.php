#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\RuntimeDoctor;

try {
    $tmpRoot = sys_get_temp_dir() . '/codex-runtime-doctor-ready-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $storageRoot = $tmpRoot . '/var/codex-users-core';
    $configPath = $tmpRoot . '/config.php';

    if (!mkdir($tmpRoot, 0775, true) && !is_dir($tmpRoot)) {
        throw new \RuntimeException("Cannot create temp root {$tmpRoot}");
    }

    file_put_contents($configPath, <<<'PHP'
<?php
return [
    'codex' => [
        'bin' => 'codex',
        'cwd' => '__CWD__',
    ],
    'router' => [
        'base_url' => 'https://router.local',
        'core_token' => 'token',
    ],
    'file_exchange' => [
        'base_url' => 'https://files.ioannidis.ru',
        'token' => 'file-token',
    ],
    'transcription' => [
        'api_key' => 'transcription-token',
        'model' => 'gpt-transcribe',
    ],
    'speech' => [
        'model' => 'gpt-4o-mini-tts',
    ],
    'voice_response' => [
        'default_voice' => 'cedar',
        'allowed_voices' => ['cedar', 'nova'],
    ],
    'storage' => [
        'root' => '__ROOT__',
    ],
];
PHP);

    $configSource = str_replace(
        ['__CWD__', '__ROOT__'],
        [addslashes('/home/web'), addslashes($storageRoot)],
        (string) file_get_contents($configPath)
    );
    file_put_contents($configPath, $configSource);

    $issues = (new RuntimeDoctor())->diagnose($configPath);
    if ($issues !== []) {
        throw new \RuntimeException("doctor reported issues:\n" . implode("\n", $issues));
    }

    $configSource = str_replace("'api_key' => 'transcription-token'", "'api_key' => ''", $configSource);
    file_put_contents($configPath, $configSource);
    $issues = (new RuntimeDoctor())->diagnose($configPath);
    if (!in_array('transcription.api_key is empty', $issues, true)) {
        throw new \RuntimeException('doctor accepted empty transcription.api_key');
    }

    $configSource = str_replace("'api_key' => ''", "'api_key' => 'transcription-token'", $configSource);
    $configSource = str_replace("'model' => 'gpt-transcribe'", "'model' => ''", $configSource);
    file_put_contents($configPath, $configSource);
    $issues = (new RuntimeDoctor())->diagnose($configPath);
    if (!in_array('transcription.model is empty', $issues, true)) {
        throw new \RuntimeException('doctor accepted empty transcription.model');
    }

    fwrite(STDOUT, "Doctor ready-config smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Doctor ready-config smoke failed: {$e->getMessage()}\n");
    exit(1);
}
