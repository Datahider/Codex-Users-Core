#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\MainProcessGuard;
use CodexRuntime\RuntimePaths;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $tmpRoot = sys_get_temp_dir() . '/codex-runtime-minimal-config-' . substr(bin2hex(random_bytes(4)), 0, 8);
    mkdir($tmpRoot, 0775, true);

    $configPath = $tmpRoot . '/config.php';
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
        'root' => '__TMP__/var',
    ],
];
PHP);
    $configSource = str_replace('__TMP__', addslashes($tmpRoot), (string) file_get_contents($configPath));
    file_put_contents($configPath, $configSource);

    $config = Config::fromFile($configPath);
    $paths = new RuntimePaths($config);

    assertSame($tmpRoot . '/var/log/runtime.log', $paths->logFile(), 'log file');
    assertSame($tmpRoot . '/var/state/manager-state.json', $paths->managerStateFile(), 'manager state file');
    assertSame($tmpRoot . '/var/run/core-main.lock', $paths->mainLockFile(), 'main lock file');
    assertSame($tmpRoot . '/var/run/core-main.pid', $paths->mainPidFile(), 'main pid file');
    assertSame($tmpRoot . '/var/run/router-ingress-worker.lock', $paths->workerLockFile('router_ingress_worker'), 'router worker lock');
    assertSame($tmpRoot . '/var/run/router-ingress-worker.pid', $paths->workerPidFile('router_ingress_worker'), 'router worker pid');
    assertSame($tmpRoot . '/var/run/router-ingress-worker.shutdown.flag', $paths->workerShutdownFlagFile('router_ingress_worker'), 'router worker shutdown flag');

    $logger = new Logger($paths->logFile());
    $guard = new MainProcessGuard($config, $logger);
    $guard->acquire();

    if (!is_file($paths->mainPidFile())) {
        throw new RuntimeException('MainProcessGuard did not create default pid file');
    }

    fwrite(STDOUT, "Minimal config surface smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Minimal config surface smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        $expectedText = var_export($expected, true);
        $actualText = var_export($actual, true);
        throw new RuntimeException("Assertion failed for {$label}: expected {$expectedText}, got {$actualText}");
    }
}
