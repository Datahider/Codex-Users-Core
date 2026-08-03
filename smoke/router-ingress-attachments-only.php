#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\ControlIngress;
use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\Router\CoreEventSourceInterface;
use CodexRuntime\Router\RouterIngressWorker;
use CodexRuntime\WorkerShutdownFlag;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $tmpRoot = sys_get_temp_dir() . '/codex-runtime-router-attachments-only-' . substr(bin2hex(random_bytes(4)), 0, 8);
    mkdir($tmpRoot, 0775, true);

    $configPath = $tmpRoot . '/config.php';
    file_put_contents($configPath, <<<'PHP'
<?php
return [
    'background' => [
        'router_ingress_worker_pid_file' => '__TMP__/run/router-ingress-worker.pid',
        'router_ingress_worker_shutdown_flag_file' => '__TMP__/run/router-ingress-worker.shutdown.flag',
    ],
    'manager_queue' => [
        'queue_new' => '__TMP__/manager-queue/new',
        'queue_running' => '__TMP__/manager-queue/running',
        'queue_done' => '__TMP__/manager-queue/done',
        'queue_failed' => '__TMP__/manager-queue/failed',
        'results_dir' => '__TMP__/manager-results',
        'lock_file' => '__TMP__/run/manager-worker.lock',
    ],
    'router' => [
        'core_events_wait_seconds' => 0,
        'core_events_limit' => 1,
        'retry_unavailable_after_seconds' => 1,
        'state_file' => '__TMP__/state/router-state.json',
        'lock_file' => '__TMP__/run/router-ingress-worker.lock',
    ],
    'storage' => [
        'root' => '__TMP__',
        'state_file' => '__TMP__/state/state.json',
        'manager_state_file' => '__TMP__/state/manager-state.json',
        'log_file' => '__TMP__/log/runtime.log',
        'tmp_dir' => '__TMP__/tmp',
    ],
];
PHP);
    $configSource = str_replace('__TMP__', addslashes($tmpRoot), (string) file_get_contents($configPath));
    file_put_contents($configPath, $configSource);

    $config = Config::fromFile($configPath);
    $logger = new Logger((string) $config->require('storage', 'log_file'));
    $events = new EventRepository($config);
    $controls = new ControlIngress(new CommandRepository($config));
    $stateStore = new JsonFileStore((string) $config->require('router', 'state_file'));
    $shutdown = new WorkerShutdownFlag($config, 'background', 'router_ingress_worker_shutdown_flag_file');

    $source = new class implements CoreEventSourceInterface {
        public function pollNextEvent(int $afterId, int $wait, int $limit = 1): ?array
        {
            return [
                'router_event_id' => 501,
                'type' => 'user_message',
                'priority' => 50,
                'session_id' => 'cli_main:session-42',
                'text' => '',
                'meta' => [
                    'source' => 'router',
                    'attachments' => [
                        [
                            'url' => 'https://files.ioannidis.ru/GPVn',
                            'type' => 'document',
                            'name' => 'files-verify-kccq.txt',
                            'size_bytes' => 37,
                        ],
                    ],
                    'router_meta' => ['source' => 'smoke'],
                ],
            ];
        }
    };

    $worker = new RouterIngressWorker($config, $logger, $source, $events, $controls, $stateStore, $shutdown);
    assertSame(true, $worker->pollOnce(), 'processed');

    $queuedPath = $events->nextPendingPath();
    if ($queuedPath === null) {
        throw new RuntimeException('Router ingress worker did not enqueue an attachments-only event');
    }

    $event = $events->loadEvent($queuedPath);
    assertSame('', $event['text'] ?? null, 'event text');
    assertSame('https://files.ioannidis.ru/GPVn', $event['meta']['attachments'][0]['url'] ?? null, 'attachment url');

    fwrite(STDOUT, "Router ingress attachments-only smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Router ingress attachments-only smoke failed: {$e->getMessage()}\n");
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
