#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\CodexProcess;
use CodexRuntime\Config;
use CodexRuntime\Logger;

require_once __DIR__ . '/../src/bootstrap.php';

$tmpRoot = sys_get_temp_dir() . '/codex-runtime-command-order-' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0777, true);

try {
    $config = new Config([
        'codex' => [
            'bin' => 'codex',
            'extra_args' => ['-s', 'read-only', '--skip-git-repo-check', '--json'],
        ],
        'storage' => [
            'log_file' => $tmpRoot . '/runtime.log',
        ],
    ]);

    $process = new CodexProcess($config, new Logger($tmpRoot . '/runtime.log'));
    $method = new ReflectionMethod($process, 'buildCommand');
    $method->setAccessible(true);

    $command = $method->invoke($process, 'test prompt', 'session-123', $tmpRoot . '/last.txt');
    assertSame(
        ['codex', 'exec', '-s', 'read-only', '--skip-git-repo-check', '--json', 'resume', 'session-123', '-o'],
        array_slice($command, 0, 9),
        'resume command prefix'
    );
    assertSame('-', $command[count($command) - 1], 'resume command reads prompt from stdin');

    $commandWithoutSession = $method->invoke($process, 'test prompt', null, $tmpRoot . '/last.txt');
    assertSame(
        ['codex', 'exec', '-s', 'read-only', '--skip-git-repo-check', '--json', '-o'],
        array_slice($commandWithoutSession, 0, 7),
        'new session command prefix'
    );
    assertSame('-', $commandWithoutSession[count($commandWithoutSession) - 1], 'new session command reads prompt from stdin');

    fwrite(STDOUT, "Codex command order smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Codex command order smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    deleteTree($tmpRoot);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        $expectedText = var_export($expected, true);
        $actualText = var_export($actual, true);
        throw new RuntimeException("Assertion failed for {$label}: expected {$expectedText}, got {$actualText}");
    }
}

function deleteTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemPath = $path . '/' . $item;
        if (is_dir($itemPath)) {
            deleteTree($itemPath);
            continue;
        }

        unlink($itemPath);
    }

    rmdir($path);
}
