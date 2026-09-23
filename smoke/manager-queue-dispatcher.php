<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\ManagerQueue\ManagerQueueDispatcher;

require dirname(__DIR__) . '/src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/codex-manager-dispatcher-' . bin2hex(random_bytes(4));

try {
    $config = new Config(['storage' => ['root' => $tmp_root]]);
    $events = new EventRepository($config);
    $events->enqueue(['id' => 'a-1', 'type' => 'user_message', 'session_id' => 'session-a', 'text' => 'A1', 'created_at' => '2026-01-01T00:00:00+00:00']);
    $events->enqueue(['id' => 'b-1', 'type' => 'user_message', 'session_id' => 'session-b', 'text' => 'B1', 'created_at' => '2026-01-01T00:00:01+00:00']);
    $events->enqueue(['id' => 'a-2', 'type' => 'user_message', 'session_id' => 'session-a', 'text' => 'A2', 'created_at' => '2026-01-01T00:00:02+00:00']);

    $first_dispatcher = new ManagerQueueDispatcher($config, $events);
    $second_dispatcher = new ManagerQueueDispatcher($config, $events);

    $first = $first_dispatcher->claimNext();
    assertSame('a-1', $first?->event['id'] ?? null, 'first worker claims oldest event');
    assertSame('session-a', $first?->session_id, 'first worker locks session A');

    $second = $second_dispatcher->claimNext();
    assertSame('b-1', $second?->event['id'] ?? null, 'second worker skips locked session A');
    assertSame('session-b', $second?->session_id, 'second worker locks session B');

    $next_a = $first_dispatcher->claimNextForSession('session-a');
    assertSame('a-2', $next_a['event']['id'] ?? null, 'first worker drains its session');
    assertSame(null, $first_dispatcher->claimNextForSession('session-a'), 'session A queue is empty');

    $first?->release();
    $second?->release();
    fwrite(STDOUT, "Manager queue dispatcher smoke: OK\n");
} finally {
    removeTree($tmp_root);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        is_dir($child) ? removeTree($child) : unlink($child);
    }
    rmdir($path);
}
