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
    $process = new CodexProcess($config, new Logger($tmpRoot . '/runtime.log'), new ActiveTurnRegistry($tmpRoot . '/active-turn.json'), $configPath);
    $method = new ReflectionMethod($process, 'processEnvironment');
    $method->setAccessible(true);

    $env = $method->invoke($process, 'codex-session', 'runtime-session');
    assertSame($tmpRoot . '/var', $env['CODEX_STORAGE_ROOT'] ?? null, 'storage root');
    assertSame('codex-session', $env['CODEX_SID'] ?? null, 'codex sid');
    assertSame('runtime-session', $env['RUNTIME_SID'] ?? null, 'runtime sid');
    $commandMethod = new ReflectionMethod($process, 'buildCommand');
    $commandMethod->setAccessible(true);
    $command = $commandMethod->invoke($process, 'prompt', null, $tmpRoot . '/output');
    assertContains('mcp_servers.codex_runtime.default_tools_approval_mode="approve"', $command, 'server approval mode');
    assertNotContainsPrefix('mcp_servers.codex_runtime.enabled_tools=', $command, 'dynamic tools are not allowlisted');
    assertNotContainsPrefix('mcp_servers.codex_runtime.tools.', $command, 'per-tool approval removed');

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

function assertContains(string $needle, array $values, string $label): void
{
    if (!in_array($needle, $values, true)) {
        throw new RuntimeException("Assertion failed for {$label}: missing {$needle}");
    }
}

function assertNotContainsPrefix(string $prefix, array $values, string $label): void
{
    foreach ($values as $value) {
        if (str_starts_with((string) $value, $prefix)) {
            throw new RuntimeException("Assertion failed for {$label}: unexpected {$value}");
        }
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
