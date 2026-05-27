#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\Config;
use CodexRuntime\RuntimeInstaller;

$configPath = $argv[1] ?? (__DIR__ . '/../config/config.example.php');

try {
    $config = Config::fromFile($configPath);
    $installer = new RuntimeInstaller();
    $installer->ensureEnvironment();
    $installer->ensureStorageLayout($config);

    fwrite(STDOUT, "Setup complete.\n");
    fwrite(STDOUT, "Next: copy config/config.example.php to config/config.php and fill router.base_url and router.core_token.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Setup failed: {$e->getMessage()}\n");
    exit(1);
}
