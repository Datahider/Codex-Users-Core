#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\BackgroundSupervisor;
use CodexRuntime\Config;
use CodexRuntime\Logger;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $config = new Config([
        'background' => [
            'enabled' => true,
        ],
        'storage' => [
            'log_file' => __DIR__ . '/../var/log/smoke.log',
        ],
    ]);
    $logger = new Logger(__DIR__ . '/../var/log/smoke.log');
    $supervisor = new BackgroundSupervisor($config, $logger, __DIR__ . '/../config/config.php');

    $reflection = new ReflectionClass($supervisor);
    $method = $reflection->getMethod('workerBootstrapCode');
    $method->setAccessible(true);

    $routerIngress = (string) $method->invoke($supervisor, 'router_ingress_worker');
    $manager = (string) $method->invoke($supervisor, 'manager_worker');
    $control = (string) $method->invoke($supervisor, 'control_watcher');

    assertContains('RouterIngressWorker', $routerIngress, 'router ingress worker bootstrap');
    assertContains('CoreEventSource', $routerIngress, 'core event source bootstrap');
    assertContains('RouterTransportClient', $manager, 'manager router transport bootstrap');
    assertContains('RouterStatusMessageService', $manager, 'manager router status bootstrap');
    assertContains('RouterTransportClient', $control, 'control watcher router transport bootstrap');
    assertNotContains('QueueTransportClient', $manager, 'manager queue transport bootstrap');
    assertNotContains('QueueStatusMessageService', $manager, 'manager queue status bootstrap');
    assertNotContains('QueueTransportClient', $control, 'control queue transport bootstrap');
    assertNotContains('QueueStatusMessageService', $control, 'control queue status bootstrap');

    fwrite(STDOUT, "Background supervisor router bootstrap smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Background supervisor router bootstrap smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertContains(string $needle, string $haystack, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("Assertion failed for {$label}: missing {$needle}");
    }
}

function assertNotContains(string $needle, string $haystack, string $label): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException("Assertion failed for {$label}: unexpected {$needle}");
    }
}
