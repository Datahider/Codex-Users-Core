#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\Router\CoreEventSourceInterface;
use CodexRuntime\Router\RouterIngressWorker;
use CodexRuntime\WorkerShutdownFlag;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $tmpRoot = sys_get_temp_dir() . '/codex-runtime-router-ingress-smoke-' . substr(bin2hex(random_bytes(4)), 0, 8);
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
    $stateStore = new JsonFileStore((string) $config->require('router', 'state_file'));
    $shutdown = new WorkerShutdownFlag($config, 'background', 'router_ingress_worker_shutdown_flag_file');

    $source = new class implements CoreEventSourceInterface {
        public int $after_id = -1;
        public int $wait = -1;
        public int $limit = -1;

        public function pollNextEvent(int $afterId, int $wait, int $limit = 1): ?array
        {
            $this->after_id = $afterId;
            $this->wait = $wait;
            $this->limit = $limit;

            return [
                'router_event_id' => 501,
                'type' => 'user_message',
                'priority' => 50,
                'session_id' => 'cli_main:session-42',
                'text' => 'router ping',
                'meta' => [
                    'source' => 'router',
                    'attachments' => [],
                    'router_meta' => ['source' => 'smoke'],
                ],
            ];
        }
    };

    $worker = new RouterIngressWorker($config, $logger, $source, $events, $stateStore, $shutdown);
    $processed = $worker->pollOnce();
    assertSame(true, $processed, 'processed');
    assertSame(0, $source->after_id, 'after_id passed to source');
    assertSame(0, $source->wait, 'wait passed to source');
    assertSame(1, $source->limit, 'limit passed to source');

    $queuedPath = $events->nextPendingPath();
    if ($queuedPath === null) {
        throw new RuntimeException('Router ingress worker did not enqueue a manager event');
    }

    $event = $events->loadEvent($queuedPath);
    assertSame('user_message', $event['type'] ?? null, 'event type');
    assertSame('cli_main:session-42', $event['session_id'] ?? null, 'runtime session id');
    assertSame('router ping', $event['text'] ?? null, 'event text');
    assertSame('router', $event['meta']['source'] ?? null, 'event source');

    $state = $stateStore->read();
    assertSame(501, $state['router_after_id'] ?? null, 'router after id');

    fwrite(STDOUT, "Router ingress worker smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Router ingress worker smoke failed: {$e->getMessage()}\n");
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
