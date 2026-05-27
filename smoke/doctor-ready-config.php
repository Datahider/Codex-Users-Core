#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\RuntimeDoctor;

try {
    $tmpRoot = sys_get_temp_dir() . '/codex-runtime-doctor-ready-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $storageRoot = $tmpRoot . '/runtime';
    $configPath = $tmpRoot . '/config.php';

    if (!mkdir($storageRoot, 0775, true) && !is_dir($storageRoot)) {
        throw new \RuntimeException("Cannot create storage root {$storageRoot}");
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

    fwrite(STDOUT, "Doctor ready-config smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Doctor ready-config smoke failed: {$e->getMessage()}\n");
    exit(1);
}
