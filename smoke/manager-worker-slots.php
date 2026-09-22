<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\ManagerWorkerSlot;

require dirname(__DIR__) . '/src/bootstrap.php';

$probe_root = $argv[1] ?? null;
if (($argv[0] ?? '') !== '' && ($argv[2] ?? '') === 'probe') {
    $probe_config = new Config([
        'storage' => ['root' => $probe_root],
        'manager_queue' => ['max_workers' => 1],
    ]);
    $probe_slot = new ManagerWorkerSlot($probe_config);
    $slot_path = (new CodexRuntime\RuntimePaths($probe_config))->managerWorkerSlotFile(1);
    $inherited = false;
    foreach (glob('/proc/self/fd/*') ?: [] as $fd_path) {
        if (readlink($fd_path) === $slot_path) {
            $inherited = true;
            break;
        }
    }
    fwrite(STDOUT, json_encode(['inherited' => $inherited]) . PHP_EOL);
    exit(0);
}

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
    $held_diagnostics = $first->diagnostics();
    assertSame(true, $held_diagnostics['acquired'] ?? null, 'diagnostics acquired state');
    assertSame(true, $held_diagnostics['exclusive_lock_observed'] ?? null, 'diagnostics observes held lock');
    assertSame(1, $held_diagnostics['slot'] ?? null, 'diagnostics slot number');
    assertSame(true, is_string($held_diagnostics['path'] ?? null), 'diagnostics path');
    assertSame(true, is_int($held_diagnostics['inode'] ?? null), 'diagnostics inode');
    assertSame(true, is_int($held_diagnostics['device'] ?? null), 'diagnostics device');
    assertSame(false, $second->acquire(), 'second worker is rejected by capacity');
    assertSame(null, $second->number(), 'rejected worker has no slot');

    $command = [PHP_BINARY, __FILE__, $tmp_root, 'probe'];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start child slot probe');
    }
    $child_stdout = stream_get_contents($pipes[1]);
    $child_stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $child_exit_code = proc_close($process);
    assertSame(0, $child_exit_code, 'child slot probe exit code: ' . trim((string) $child_stderr));
    $child_result = json_decode((string) $child_stdout, true, 512, JSON_THROW_ON_ERROR);
    assertSame(false, $child_result['inherited'] ?? null, 'exec child does not inherit slot descriptor');

    $first->release();
    $released_diagnostics = $first->diagnostics();
    assertSame(false, $released_diagnostics['acquired'] ?? null, 'diagnostics released state');
    assertSame(false, $released_diagnostics['exclusive_lock_observed'] ?? null, 'diagnostics observes released lock');
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
