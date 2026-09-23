#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\OptionalFeatureConfig;
use CodexRuntime\RuntimeDoctor;

require dirname(__DIR__) . '/src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/core-optional-config-' . bin2hex(random_bytes(4));

try {
    mkdir($tmp_root, 0775, true);
    $config_path = $tmp_root . '/config.php';
    file_put_contents($config_path, '<?php return ' . var_export([
        'codex' => ['cwd' => '/home/web'],
        'router' => [
            'base_url' => 'https://router.local',
            'core_token' => 'token',
        ],
        'storage' => ['root' => $tmp_root . '/storage'],
    ], true) . ';');

    assertSame([], (new RuntimeDoctor())->diagnose($config_path), 'optional values do not block startup');

    $features = new OptionalFeatureConfig(Config::fromFile($config_path));
    assertSame(
        ['transcription.api_key', 'transcription.model'],
        $features->missingTranscriptionValues(),
        'missing transcription values'
    );
    assertSame(
        ['file_exchange.base_url', 'file_exchange.token'],
        $features->missingFileExchangeValues(),
        'missing file exchange values'
    );
    assertSame(
        [
            'transcription.api_key',
            'speech.model',
            'voice_response.default_voice',
            'voice_response.allowed_voices',
            'file_exchange.base_url',
            'file_exchange.token',
        ],
        $features->missingVoiceResponseValues(),
        'missing voice response values'
    );
    assertSame('codex', $features->codexBin(), 'missing codex.bin default');

    fwrite(STDOUT, "Optional feature config smoke: OK\n");
} finally {
    removeTree($tmp_root);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $child = $path . '/' . $name;
        is_dir($child) ? removeTree($child) : unlink($child);
    }
    rmdir($path);
}
