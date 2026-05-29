#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\ActiveTurnRegistry;
use CodexRuntime\CodexProcess;
use CodexRuntime\Config;
use CodexRuntime\Logger;

require_once __DIR__ . '/../src/bootstrap.php';

$tmpRoot = sys_get_temp_dir() . '/codex-process-config-path-' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0777, true);

try {
    $configPath = $tmpRoot . '/runtime.php';
    file_put_contents($configPath, <<<'PHP'
<?php
return [
    'codex' => [
        'bin' => 'codex',
    ],
    'storage' => [
        'root' => '__TMP__/var',
    ],
];
PHP);
    file_put_contents($configPath, str_replace('__TMP__', addslashes($tmpRoot), (string) file_get_contents($configPath)));

    $config = Config::fromFile($configPath);
    $process = new CodexProcess($config, new Logger($tmpRoot . '/runtime.log'), new ActiveTurnRegistry($tmpRoot . '/active-turn.json'));
    $method = new ReflectionMethod($process, 'processEnvironment');
    $method->setAccessible(true);

    $env = $method->invoke($process, 'codex-session', 'runtime-session');
    assertSame($tmpRoot . '/var', $env['CODEX_STORAGE_ROOT'] ?? null, 'storage root');
    assertSame('codex-session', $env['CODEX_SID'] ?? null, 'codex sid');
    assertSame('runtime-session', $env['RUNTIME_SID'] ?? null, 'runtime sid');

    fwrite(STDOUT, "Codex process storage root smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Codex process storage root smoke failed: {$e->getMessage()}\n");
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
