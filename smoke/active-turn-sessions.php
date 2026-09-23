<?php

declare(strict_types=1);

use CodexRuntime\ActiveTurnRegistry;

require dirname(__DIR__) . '/src/bootstrap.php';

$root = sys_get_temp_dir() . '/active-turn-sessions-' . bin2hex(random_bytes(4));
$registry = new ActiveTurnRegistry($root . '/active-turn.json');

try {
    $registry->begin(['runtime_session_id' => 'session-a', 'pid' => null]);
    $registry->begin(['runtime_session_id' => 'session-b', 'pid' => null]);
    assertSame('session-a', $registry->current('session-a')['runtime_session_id'] ?? null, 'session A turn');
    assertSame('session-b', $registry->current('session-b')['runtime_session_id'] ?? null, 'session B turn');
    $registry->clear('session-a');
    assertSame(null, $registry->current('session-a'), 'session A cleared independently');
    assertSame('session-b', $registry->current('session-b')['runtime_session_id'] ?? null, 'session B remains');
    fwrite(STDOUT, "Active turn sessions smoke: OK\n");
} finally {
    if (is_dir($root . '/active-turn.json.d')) {
        foreach (glob($root . '/active-turn.json.d/*.json') ?: [] as $path) {
            unlink($path);
        }
        rmdir($root . '/active-turn.json.d');
    }
    if (is_file($root . '/active-turn.json')) {
        unlink($root . '/active-turn.json');
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
