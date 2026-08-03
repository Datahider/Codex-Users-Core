#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\Config;
use CodexRuntime\ControlIngress;
use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\Router\CoreEventSourceInterface;
use CodexRuntime\Router\RouterIngressWorker;
use CodexRuntime\WorkerShutdownFlag;

final class StubAttachmentSource implements CoreEventSourceInterface
{
    /** @var list<array<string, mixed>> */
    private array $events;

    /**
     * @param list<array<string, mixed>> $events
     */
    public function __construct(array $events)
    {
        $this->events = $events;
    }

    public function pollNextEvent(int $afterId, int $waitSeconds = 0, int $limit = 1): ?array
    {
        foreach ($this->events as $index => $event) {
            if ((int) ($event['router_event_id'] ?? 0) > $afterId) {
                unset($this->events[$index]);
                return $event;
            }
        }

        return null;
    }
}

try {
    $tmpRoot = sys_get_temp_dir() . '/codex-runtime-router-attachments-no-merge-' . substr(bin2hex(random_bytes(4)), 0, 8);
    mkdir($tmpRoot, 0775, true);

    $configPath = $tmpRoot . '/config.php';
    file_put_contents($configPath, <<<'PHP'
<?php
return [
    'router' => [
        'base_url' => 'https://router.example',
        'core_token' => 'token',
    ],
    'storage' => [
        'root' => '__TMP__/var',
    ],
];
PHP);
    $configSource = str_replace('__TMP__', addslashes($tmpRoot), (string) file_get_contents($configPath));
    file_put_contents($configPath, $configSource);

    $config = Config::fromFile($configPath);
    $logger = new Logger($tmpRoot . '/runtime.log');
    $events = new EventRepository($config);
    $controls = new ControlIngress(new CommandRepository($config));
    $stateStore = new JsonFileStore($tmpRoot . '/router-state.json');
    $shutdown = new WorkerShutdownFlag($config, 'background', 'router_ingress_worker_shutdown_flag_file', $tmpRoot . '/shutdown.flag');
    $source = new StubAttachmentSource([
        [
            'router_event_id' => 501,
            'type' => 'user_message',
            'session_id' => 'cli_main:session-42',
            'text' => 'first',
            'meta' => [
                'attachments' => [],
            ],
        ],
        [
            'router_event_id' => 502,
            'type' => 'user_message',
            'session_id' => 'cli_main:session-42',
            'text' => '',
            'meta' => [
                'attachments' => [
                    ['url' => 'https://files.ioannidis.ru/GPVn'],
                ],
            ],
        ],
    ]);

    $worker = new RouterIngressWorker($config, $logger, $source, $events, $controls, $stateStore, $shutdown);
    assertSame(true, $worker->pollOnce(), 'first poll processed');
    assertSame(true, $worker->pollOnce(), 'second poll processed');

    $queueDir = $tmpRoot . '/var/manager-queue/new';
    $files = glob($queueDir . '/*.json');
    if ($files === false) {
        throw new RuntimeException('glob failed for manager queue');
    }

    assertSame(2, count($files), 'attachment event must not merge into pending text event');

    fwrite(STDOUT, "Router ingress attachments no-merge smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Router ingress attachments no-merge smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Assertion failed for %s: expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}
