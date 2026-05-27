#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\Config;
use CodexRuntime\RuntimeInstaller;

try {
    $tmpRoot = sys_get_temp_dir() . '/codex-runtime-storage-layout-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $configPath = $tmpRoot . '/config.php';

    if (!mkdir($tmpRoot, 0775, true) && !is_dir($tmpRoot)) {
        throw new RuntimeException("Cannot create temporary root {$tmpRoot}");
    }

    file_put_contents($configPath, <<<'PHP'
<?php
return [
    'codex' => [
        'bin' => 'codex',
        'cwd' => '/home/web',
    ],
    'router' => [
        'base_url' => 'https://router.example',
        'core_token' => 'token',
    ],
    'storage' => [
        'root' => '__TMP__/runtime',
    ],
];
PHP);

    $configSource = str_replace('__TMP__', addslashes($tmpRoot), (string) file_get_contents($configPath));
    file_put_contents($configPath, $configSource);

    $config = Config::fromFile($configPath);
    $installer = new RuntimeInstaller();
    $installer->ensureStorageLayout($config);

    $expectedPaths = [
        $tmpRoot . '/runtime',
        $tmpRoot . '/runtime/run',
        $tmpRoot . '/runtime/state',
        $tmpRoot . '/runtime/log',
        $tmpRoot . '/runtime/tmp',
        $tmpRoot . '/runtime/codex-debug',
        $tmpRoot . '/runtime/manager-queue/new',
        $tmpRoot . '/runtime/manager-queue/running',
        $tmpRoot . '/runtime/manager-queue/done',
        $tmpRoot . '/runtime/manager-queue/failed',
        $tmpRoot . '/runtime/manager-results',
        $tmpRoot . '/runtime/control-queue/new',
        $tmpRoot . '/runtime/control-queue/running',
        $tmpRoot . '/runtime/control-queue/done',
        $tmpRoot . '/runtime/control-queue/failed',
        $tmpRoot . '/runtime/control-results',
        $tmpRoot . '/runtime/outbound-queue/new',
        $tmpRoot . '/runtime/outbound-queue/running',
        $tmpRoot . '/runtime/outbound-queue/done',
        $tmpRoot . '/runtime/outbound-queue/failed',
        $tmpRoot . '/runtime/scheduled-queue',
    ];

    foreach ($expectedPaths as $path) {
        if (!is_dir($path)) {
            throw new RuntimeException("Expected directory missing: {$path}");
        }
    }

    fwrite(STDOUT, "Runtime storage layout smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Runtime storage layout smoke failed: {$e->getMessage()}\n");
    exit(1);
}
