#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\Telegram\TelegramMainProcessGuard;
use CodexRuntime\Telegram\TelegramRuntime;
use losthost\BackgroundProcess\BackgroundProcess;

$configPath = $argv[1] ?? (__DIR__ . '/../config/config.php');
$config = Config::fromFile($configPath);
$logger = new Logger((string) $config->require('storage', 'log_file'));

$guard = new TelegramMainProcessGuard($config, $logger);
$guard->acquire();

$runtime = TelegramRuntime::fromConfig($config);

$projectRoot = realpath(__DIR__ . '/..');
$bootstrapPath = realpath(__DIR__ . '/../src/bootstrap.php');
$configRealPath = realpath($configPath);
if ($projectRoot === false || $bootstrapPath === false || $configRealPath === false) {
    throw new RuntimeException('Cannot resolve Telegram runtime paths');
}

$logDir = $projectRoot . '/var/log';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

$outboundLog = $logDir . '/telegram-outbound-consumer.log';
$outboundProcess = BackgroundProcess::create(<<<'PHP'
<?php
chdir(%s);
require %s;
use CodexRuntime\Telegram\TelegramRuntime;
$runtime = TelegramRuntime::fromConfigFile(%s);
$runtime->createOutboundConsumer()->run();
PHP)->run(
    $projectRoot,
    $bootstrapPath,
    $configRealPath,
    $outboundLog
);

$logger->info('Telegram transport started', [
    'pid' => getmypid(),
    'outbound_pid' => $outboundProcess->getPid(),
]);

try {
    $runtime->createLongPollingRunner()->run();
} finally {
    $pid = $outboundProcess->getPid();
    if ($pid > 0 && function_exists('posix_kill')) {
        @posix_kill($pid, SIGTERM);
    }
}
