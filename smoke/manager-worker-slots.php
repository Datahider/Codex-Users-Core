<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\ManagerWorkerSlot;

require dirname(__DIR__) . '/src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/codex-manager-slots-' . bin2hex(random_bytes(4));

try {
    $config = new Config([
        'storage' => ['root' => $tmp_root],
        'manager_queue' => ['max_workers' => 1],
    ]);

    $first = new ManagerWorkerSlot($config);
    $second = new ManagerWorkerSlot($config);

    assertSame(true, $first->acquire(), 'first worker acquires slot');
    assertSame(1, $first->number(), 'first worker slot number');
    assertSame(false, $second->acquire(), 'second worker is rejected by capacity');
    assertSame(null, $second->number(), 'rejected worker has no slot');

    $first->release();
    assertSame(true, $second->acquire(), 'slot is reusable after release');
    assertSame(1, $second->number(), 'reused slot number');
    $second->release();

    $invalid_config = new Config([
        'storage' => ['root' => $tmp_root . '/invalid'],
        'manager_queue' => ['max_workers' => 0],
    ]);
    assertThrows(
        static fn () => new ManagerWorkerSlot($invalid_config),
        'manager_queue.max_workers must be greater than zero'
    );

    fwrite(STDOUT, "Manager worker slots smoke: OK\n");
} finally {
    removeTree($tmp_root);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        assertSame($message, $error->getMessage(), 'exception message');
        return;
    }

    throw new RuntimeException('Expected exception was not thrown');
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException("Cannot scan {$path}");
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        is_dir($child) ? removeTree($child) : unlink($child);
    }
    rmdir($path);
}
