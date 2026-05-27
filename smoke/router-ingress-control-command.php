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
    $tmpRoot = sys_get_temp_dir() . '/codex-runtime-router-control-smoke-' . substr(bin2hex(random_bytes(4)), 0, 8);
    mkdir($tmpRoot, 0775, true);

    $configPath = $tmpRoot . '/config.php';
    file_put_contents($configPath, <<<'PHP'
<?php
return [
    'background' => [
        'router_ingress_worker_pid_file' => '__TMP__/run/router-ingress-worker.pid',
        'router_ingress_worker_shutdown_flag_file' => '__TMP__/run/router-ingress-worker.shutdown.flag',
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
        'log_file' => '__TMP__/log/runtime.log',
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
                'router_event_id' => 701,
                'type' => 'user_message',
                'priority' => 50,
                'session_id' => 'cli_main:session-42',
                'text' => '/stop',
                'meta' => [
                    'source' => 'router',
                ],
            ];
        }
    };

    $worker = new RouterIngressWorker($config, $logger, $source, $events, $controls, $stateStore, $shutdown);
    assertSame(true, $worker->pollOnce(), 'processed');

    if ($events->nextPendingPath() !== null) {
        throw new RuntimeException('Slash command must not be enqueued into manager queue');
    }

    $controlDir = $tmpRoot . '/control-queue/new';
    $files = glob($controlDir . '/*.json');
    if ($files === false || count($files) !== 1) {
        throw new RuntimeException('Expected exactly one control command');
    }

    $command = json_decode((string) file_get_contents($files[0]), true);
    if (!is_array($command)) {
        throw new RuntimeException('Control command is not valid json');
    }

    assertSame('transport_command', $command['type'] ?? null, 'type');
    assertSame('/stop', $command['text'] ?? null, 'text');
    assertSame('cli_main:session-42', $command['session_id'] ?? null, 'session');
    assertSame('cli_main:session-42', $command['channel_id'] ?? null, 'channel');

    fwrite(STDOUT, "Router ingress control command smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Router ingress control command smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
