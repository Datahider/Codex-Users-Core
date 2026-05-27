#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\BackgroundSupervisor;
use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\MainProcessGuard;
use CodexRuntime\RuntimeDoctor;
use CodexRuntime\RuntimeInstaller;
use CodexRuntime\RuntimePaths;
use RuntimeException;

$configPath = $argv[1] ?? (__DIR__ . '/../config/config.php');
$doctor = new RuntimeDoctor();
$issues = $doctor->diagnose($configPath);
if ($issues !== []) {
    throw new RuntimeException("Runtime configuration is invalid:\n- " . implode("\n- ", $issues));
}

$config = Config::fromFile($configPath);
$installer = new RuntimeInstaller();
$installer->ensureEnvironment();
$installer->ensureStorageLayout($config);
$logger = new Logger((string) $config->get('storage', 'log_file', (new RuntimePaths($config))->logFile()));
$guard = new MainProcessGuard($config, $logger);
$guard->acquire();
$supervisor = new BackgroundSupervisor($config, $logger, $configPath);
$supervisor->ensureStarted();
$logger->info('Core service started');

while (true) {
    try {
        $supervisor->ensureStarted();
    } catch (\Throwable $e) {
        $logger->error('Core supervisor heartbeat failed', ['error' => $e->getMessage()]);
    }

    sleep(1);
}
