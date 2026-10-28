#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Logger;
use CodexRuntime\Mcp\ToolEventSenderInterface;
use CodexRuntime\Mcp\TransportToolService;
use CodexRuntime\Mcp\TransportToolStore;

require_once __DIR__ . '/../src/bootstrap.php';

$root = sys_get_temp_dir() . '/core-transport-tools-' . bin2hex(random_bytes(4));
mkdir($root, 0775, true);
$logger = new Logger($root . '/runtime.log');
$store = new TransportToolStore($root . '/ipc', $logger);

$sender = new class($store) implements ToolEventSenderInterface {
    public array $events = [];
    public array $lists = [
        'session-a' => [[
            'name' => 'remote_a',
            'description' => 'A',
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true],
        ]],
        'session-b' => [[
            'name' => 'remote_b',
            'description' => 'B',
            'inputSchema' => ['type' => 'object'],
        ]],
        'session-empty' => [],
        'session-call-timeout' => [[
            'name' => 'remote_wait', 'description' => '', 'inputSchema' => ['type' => 'object'],
        ]],
    ];

    public function __construct(private TransportToolStore $store)
    {
    }

    public function sendToolEvent(string $runtime_session_id, array $meta): void
    {
        $this->events[] = ['runtime_session_id' => $runtime_session_id, 'meta' => $meta];
        if ($meta['type'] === 'list' && array_key_exists($runtime_session_id, $this->lists)) {
            $this->store->acceptResponse($runtime_session_id, [
                'type' => 'list',
                'request_id' => $meta['request_id'],
                'tools' => $this->lists[$runtime_session_id],
            ]);
        }
        if ($meta['type'] === 'call' && $meta['name'] === 'remote_a') {
            $this->store->acceptResponse($runtime_session_id, [
                'type' => 'result',
                'request_id' => $meta['request_id'],
                'result' => [
                    'content' => [['type' => 'text', 'text' => 'remote result']],
                    'structuredContent' => ['argument' => $meta['arguments']['value'] ?? null],
                    'isError' => false,
                ],
            ]);
        }
        if ($meta['type'] === 'call' && $meta['name'] === 'remote_error') {
            $this->store->acceptResponse($runtime_session_id, [
                'type' => 'error',
                'request_id' => $meta['request_id'],
                'error' => ['code' => 'denied', 'message' => 'No', 'data' => ['reason' => 7]],
            ]);
        }
    }
};

$local_names = ['get_core_environment', 'send_document', 'send_image', 'set_response_delivery'];
$service_a = new TransportToolService($store, $sender, $logger, 'session-a', $local_names, 600, 1.0, 1.0);
$tools_a = $service_a->warmup();
assertSame('remote_a', $tools_a[0]['name'] ?? null, 'session A list');
assertSame(['readOnlyHint' => true], $tools_a[0]['annotations'] ?? null, 'opaque standard tool fields');

$service_b = new TransportToolService($store, $sender, $logger, 'session-b', $local_names, 600, 1.0, 1.0);
assertSame('remote_b', $service_b->warmup()[0]['name'] ?? null, 'session B list');

$events_after_first_list = count($sender->events);
$service_a_cached = new TransportToolService($store, $sender, $logger, 'session-a', $local_names, 600, 1.0, 1.0);
assertSame($tools_a, $service_a_cached->warmup(), 'cached list');
assertSame($events_after_first_list, count($sender->events), 'cache suppresses outbound list');

$empty = new TransportToolService($store, $sender, $logger, 'session-empty', $local_names, 600, 1.0, 1.0);
assertSame([], $empty->warmup(), 'empty list');
$empty_event_count = count($sender->events);
assertSame([], (new TransportToolService($store, $sender, $logger, 'session-empty', $local_names, 600, 1.0, 1.0))->warmup(), 'cached empty list');
assertSame($empty_event_count, count($sender->events), 'empty list cache suppresses outbound');

$result = $service_a->call('remote_a', ['value' => 42]);
assertSame('remote result', $result['content'][0]['text'] ?? null, 'remote call result');
assertSame(42, $result['structuredContent']['argument'] ?? null, 'full result');

$sender->lists['session-error'] = [[
    'name' => 'remote_error', 'description' => 'E', 'inputSchema' => ['type' => 'object'],
]];
$error_service = new TransportToolService($store, $sender, $logger, 'session-error', $local_names, 600, 1.0, 1.0);
$error_service->warmup();
$error_result = $error_service->call('remote_error', []);
assertSame(true, $error_result['isError'] ?? null, 'transport error is tool result');
assertSame(
    '{"code":"denied","message":"No","data":{"reason":7}}',
    $error_result['content'][0]['text'] ?? null,
    'full transport error JSON'
);

$before_unknown = count($sender->events);
assertThrows(fn () => $service_a->call('unknown', []), 'Unknown MCP tool', 'unknown tool');
assertSame($before_unknown, count($sender->events), 'unknown tool stays local');

$call_timeout = new TransportToolService($store, $sender, $logger, 'session-call-timeout', $local_names, 600, 1.0, 0.03);
$call_timeout->warmup();
$started = microtime(true);
$timeout_result = $call_timeout->call('remote_wait', []);
assertSame(true, $timeout_result['isError'] ?? null, 'call timeout is bounded tool error');
assertTrue(microtime(true) - $started < 0.5, 'call timeout precedes outer timeout');
$timed_call_event = $sender->events[array_key_last($sender->events)];
assertSame(false, $store->acceptResponse('session-call-timeout', [
    'type' => 'result',
    'request_id' => $timed_call_event['meta']['request_id'],
    'result' => ['content' => []],
]), 'late call result discarded');

$timeout = new TransportToolService($store, $sender, $logger, 'session-timeout', $local_names, 600, 0.03, 0.03);
$started = microtime(true);
assertSame([], $timeout->warmup(), 'list timeout leaves local-only state');
assertTrue(microtime(true) - $started < 0.5, 'list timeout bounded');
$timeout_event_count = count($sender->events);
(new TransportToolService($store, $sender, $logger, 'session-timeout', $local_names, 600, 0.03, 0.03))->warmup();
assertSame($timeout_event_count + 1, count($sender->events), 'timeout not cached');

$sender->lists['session-invalid'] = [['name' => 'broken']];
$invalid = new TransportToolService($store, $sender, $logger, 'session-invalid', $local_names, 600, 1.0, 1.0);
assertSame([], $invalid->warmup(), 'invalid list rejected');
$invalid_count = count($sender->events);
(new TransportToolService($store, $sender, $logger, 'session-invalid', $local_names, 600, 1.0, 1.0))->warmup();
assertSame($invalid_count + 1, count($sender->events), 'invalid list not cached');

$sender->lists['session-collision'] = [[
    'name' => 'send_document', 'description' => 'collision', 'inputSchema' => ['type' => 'object'],
]];
$collision = new TransportToolService($store, $sender, $logger, 'session-collision', $local_names, 600, 1.0, 1.0);
assertSame([], $collision->warmup(), 'collision rejects whole list');

$expired = new TransportToolService($store, $sender, $logger, 'session-expired', $local_names, 0, 1.0, 1.0);
$sender->lists['session-expired'] = [];
$expired->warmup();
$expired_count = count($sender->events);
(new TransportToolService($store, $sender, $logger, 'session-expired', $local_names, 0, 1.0, 1.0))->warmup();
assertSame($expired_count + 1, count($sender->events), 'expired cache refreshes');

$request_a = $store->register('parallel-session', 'call', 1.0);
$request_b = $store->register('parallel-session', 'call', 1.0);
assertTrue($request_a !== $request_b, 'unique request ids');
assertSame(true, $store->acceptResponse('parallel-session', ['type' => 'result', 'request_id' => $request_b, 'result' => ['content' => []]]), 'second response accepted first');
assertSame(true, $store->acceptResponse('parallel-session', ['type' => 'result', 'request_id' => $request_a, 'result' => ['content' => []]]), 'first response accepted second');
assertSame($request_a, $store->await($request_a, 0.1)['request_id'] ?? null, 'first correlation');
assertSame($request_b, $store->await($request_b, 0.1)['request_id'] ?? null, 'second correlation');
assertSame(false, $store->acceptResponse('parallel-session', ['type' => 'result', 'request_id' => $request_a, 'result' => ['content' => []]]), 'duplicate discarded');
assertSame(false, $store->acceptResponse('foreign-session', ['type' => 'result', 'request_id' => $request_b, 'result' => ['content' => []]]), 'foreign response discarded');

$single_flight_counter = $root . '/single-flight-count';
file_put_contents($single_flight_counter, '0');
$children = [];
for ($i = 0; $i < 2; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $child_store = new TransportToolStore($root . '/ipc', $logger);
        $child_sender = new class($child_store, $single_flight_counter) implements ToolEventSenderInterface {
            public function __construct(private TransportToolStore $store, private string $counter) {}
            public function sendToolEvent(string $runtime_session_id, array $meta): void
            {
                $handle = fopen($this->counter, 'c+');
                flock($handle, LOCK_EX);
                $count = (int) stream_get_contents($handle);
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, (string) ($count + 1));
                fflush($handle);
                flock($handle, LOCK_UN);
                fclose($handle);
                usleep(50_000);
                $this->store->acceptResponse($runtime_session_id, [
                    'type' => 'list', 'request_id' => $meta['request_id'], 'tools' => [],
                ]);
            }
        };
        (new TransportToolService($child_store, $child_sender, $logger, 'single-flight', $local_names, 600, 1.0, 1.0))->warmup();
        exit(0);
    }
    $children[] = $pid;
}
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    assertSame(0, pcntl_wexitstatus($status), 'single-flight child status');
}
assertSame('1', file_get_contents($single_flight_counter), 'single-flight list request count');

fwrite(STDOUT, "Transport MCP tools smoke: OK\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $actual, string $label): void
{
    assertSame(true, $actual, $label);
}

function assertThrows(callable $callable, string $message, string $label): void
{
    try {
        $callable();
    } catch (Throwable $e) {
        assertSame($message, $e->getMessage(), $label);
        return;
    }
    throw new RuntimeException("Assertion failed for {$label}: exception not thrown");
}
