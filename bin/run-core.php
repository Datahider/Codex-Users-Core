#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\BackgroundSupervisor;
use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\MainProcessGuard;
use CodexRuntime\RuntimePaths;

$configPath = $argv[1] ?? (__DIR__ . '/../config/config.php');
$config = Config::fromFile($configPath);
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
