#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\CommandWatcher\CommandRunner;
use CodexRuntime\Config;

require_once __DIR__ . '/../src/bootstrap.php';

$tmpRoot = sys_get_temp_dir() . '/command-runner-home-defaults-' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0777, true);

try {
    $homeDir = $tmpRoot . '/home';
    if (!mkdir($homeDir, 0775, true) && !is_dir($homeDir)) {
        throw new RuntimeException("Cannot create directory {$homeDir}");
    }

    putenv("HOME={$homeDir}");

    $config = new Config([]);
    $runner = new CommandRunner($config, 'exec_watcher');

    $cwdMethod = new ReflectionMethod($runner, 'resolveWorkingDirectory');
    $cwdMethod->setAccessible(true);

    $rootsMethod = new ReflectionMethod($runner, 'allowedWorkdirs');
    $rootsMethod->setAccessible(true);

    assertSame($homeDir, $cwdMethod->invoke($runner, []), 'default cwd');
    assertSame([$homeDir], $rootsMethod->invoke($runner), 'default allowed workdirs');

    fwrite(STDOUT, "Command runner home defaults smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Command runner home defaults smoke failed: {$e->getMessage()}\n");
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
