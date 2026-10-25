#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\ControlIngress;
use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\Mcp\TransportToolStore;
use CodexRuntime\Router\CoreEventSourceInterface;
use CodexRuntime\Router\RouterIngressWorker;
use CodexRuntime\WorkerShutdownFlag;

require_once __DIR__ . '/../src/bootstrap.php';

$root = sys_get_temp_dir() . '/core-tool-ingress-' . bin2hex(random_bytes(4));
mkdir($root, 0775, true);
$config_file = $root . '/config.php';
file_put_contents($config_file, '<?php return ' . var_export([
    'storage' => ['root' => $root, 'manager_state_file' => $root . '/manager.json'],
    'router' => ['state_file' => $root . '/router.json'],
    'manager_queue' => ['pending_dir' => $root . '/pending', 'running_dir' => $root . '/running', 'failed_dir' => $root . '/failed'],
    'control_queue' => ['pending_dir' => $root . '/control-pending'],
], true) . ';');
$config = Config::fromFile($config_file);
$logger = new Logger($root . '/runtime.log');
$store = new TransportToolStore($root . '/tool-ipc', $logger);
$request_id = $store->register('runtime-1', 'list', 1.0);
$source = new class($request_id) implements CoreEventSourceInterface {
    private bool $served = false;
    public function __construct(private string $request_id) {}
    public function pollNextEvent(int $afterId, int $wait, int $limit = 1): ?array
    {
        if ($this->served) { return null; }
        $this->served = true;
        return [
            'router_event_id' => 9,
            'type' => 'router_event',
            'session_id' => 'runtime-1',
            'text' => '',
            'kind' => 'tool',
            'attachments' => [],
            'meta' => ['type' => 'list', 'request_id' => $this->request_id, 'tools' => []],
        ];
    }
};
$events = new EventRepository($config);
$worker = new RouterIngressWorker(
    $config,
    $logger,
    $source,
    $events,
    new ControlIngress(new CommandRepository($config)),
    new JsonFileStore($root . '/router.json'),
    new WorkerShutdownFlag($config, 'background', 'router_ingress_worker_shutdown_flag_file', $root . '/shutdown'),
    $store
);
assertSame(true, $worker->pollOnce(), 'tool event consumed');
assertSame('list', $store->await($request_id, 0.1)['type'] ?? null, 'tool response delivered to waiter');
assertSame(null, $events->nextPendingPath(), 'tool event did not enqueue manager event');
assertSame(9, (new JsonFileStore($root . '/router.json'))->read()['router_after_id'] ?? null, 'cursor advanced');

fwrite(STDOUT, "Router tool ingress smoke: OK\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
