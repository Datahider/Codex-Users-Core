#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\MainProcessGuard;
use CodexRuntime\Max\MaxRuntime;
use losthost\BackgroundProcess\BackgroundProcess;

$configPath = $argv[1] ?? (__DIR__ . '/../config/config.php');
$config = Config::fromFile($configPath);
$logger = new Logger((string) $config->require('storage', 'log_file'));

cleanupStaleMaxProcesses($config, $logger);

$guard = new MainProcessGuard($config, $logger, 'max_transport_lock_file', 'max_transport_pid_file');
$guard->acquire();

$runtime = MaxRuntime::fromConfig($config, $configPath);
$runtime->clearPendingShutdown();

$projectRoot = realpath(__DIR__ . '/..');
$bootstrapPath = realpath(__DIR__ . '/../src/bootstrap.php');
$configRealPath = realpath($configPath);
if ($projectRoot === false || $bootstrapPath === false || $configRealPath === false) {
    throw new RuntimeException('Cannot resolve MAX runtime paths');
}

$logDir = $projectRoot . '/var/log';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

$outboundLog = $logDir . '/max-outbound-consumer.log';
$outboundProcess = BackgroundProcess::create(<<<'PHP'
<?php
chdir(%s);
require %s;
use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\MainProcessGuard;
use CodexRuntime\Max\MaxRuntime;
$config = Config::fromFile(%s);
$logger = new Logger((string) $config->require('storage', 'log_file'));
$guard = new MainProcessGuard($config, $logger, 'max_outbound_consumer_lock_file', 'max_outbound_consumer_pid_file');
$guard->acquire();
$runtime = MaxRuntime::fromConfig($config);
$runtime->createOutboundConsumer()->run();
PHP)->run(
    $projectRoot,
    $bootstrapPath,
    $configRealPath,
    $outboundLog
);

$logger->info('MAX transport started', [
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

function cleanupStaleMaxProcesses(Config $config, Logger $logger): void
{
    $transportPidFile = (string) $config->require('background', 'max_transport_pid_file');
    $outboundPidFile = (string) $config->require('background', 'max_outbound_consumer_pid_file');

    $transportPid = readPid($transportPidFile);
    $outboundPid = readPid($outboundPidFile);

    $transportAlive = $transportPid !== null && isPidAlive($transportPid);
    $outboundAlive = $outboundPid !== null && isPidAlive($outboundPid);

    if (!$transportAlive && $outboundAlive && $outboundPid !== null && function_exists('posix_kill')) {
        @posix_kill($outboundPid, SIGTERM);
        usleep(250000);
        $outboundAlive = isPidAlive($outboundPid);

        $logger->warning('Killed stale MAX outbound consumer before transport startup', [
            'outbound_pid' => $outboundPid,
            'transport_pid' => $transportPid,
            'outbound_still_alive' => $outboundAlive,
        ]);
    }

    if (!$transportAlive && is_file($transportPidFile)) {
        @unlink($transportPidFile);
    }

    if (!$outboundAlive && is_file($outboundPidFile)) {
        @unlink($outboundPidFile);
    }
}

function readPid(string $pidFile): ?int
{
    if (!is_file($pidFile)) {
        return null;
    }

    $pid = (int) trim((string) file_get_contents($pidFile));

    return $pid > 0 ? $pid : null;
}

function isPidAlive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    return is_dir('/proc/' . $pid);
}
