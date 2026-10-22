#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\RuntimeDoctor;

$tmp_root = sys_get_temp_dir() . '/codex-doctor-provider-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    $fake_claude = $tmp_root . '/claude';
    file_put_contents($fake_claude, "#!/bin/sh\nexit 0\n");
    chmod($fake_claude, 0775);
    $missing_codex = $tmp_root . '/missing-codex';
    $missing_claude = $tmp_root . '/missing-claude';

    $claude_ready = diagnose($tmp_root, 'claude-ready', [
        'provider' => ['default' => 'claude'],
        'codex' => ['bin' => $missing_codex],
        'claude' => ['bin' => $fake_claude, 'model' => 'opus', 'models' => ['opus'], 'cwd' => $tmp_root],
    ]);
    assertSame([], $claude_ready, 'claude default without codex is ready');

    $claude_missing = diagnose($tmp_root, 'claude-missing', [
        'provider' => ['default' => 'claude'],
        'codex' => ['bin' => $missing_codex],
        'claude' => ['bin' => $missing_claude, 'model' => 'opus', 'models' => ['opus'], 'cwd' => $tmp_root],
    ]);
    assertSame(
        ["provider.default claude is not available: {$missing_claude} не найден в PATH"],
        $claude_missing,
        'unavailable claude default'
    );

    $codex_missing = diagnose($tmp_root, 'codex-missing', [
        'codex' => ['bin' => $missing_codex, 'cwd' => $tmp_root],
    ]);
    assertSame(
        ["provider.default codex is not available: {$missing_codex} не найден в PATH"],
        $codex_missing,
        'unavailable codex default'
    );

    $invalid = diagnose($tmp_root, 'invalid', [
        'provider' => ['default' => 'gemini'],
        'codex' => ['bin' => $fake_claude, 'cwd' => $tmp_root],
    ]);
    assertSame(['provider.default must be codex or claude: gemini'], $invalid, 'invalid provider.default');

    fwrite(STDOUT, "Doctor provider default smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Doctor provider default smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    deleteTree($tmp_root);
}

/**
 * @param array<string, mixed> $sections
 * @return list<string>
 */
function diagnose(string $tmp_root, string $name, array $sections): array
{
    $config_path = $tmp_root . '/' . $name . '.php';
    $config = $sections + [
        'router' => ['base_url' => 'https://router.local', 'core_token' => 'token'],
        'storage' => ['root' => $tmp_root . '/' . $name . '-var'],
    ];
    file_put_contents($config_path, '<?php return ' . var_export($config, true) . ";\n");

    return array_values(array_filter(
        (new RuntimeDoctor())->diagnose($config_path),
        static fn (string $issue): bool => !str_contains($issue, 'logger')
    ));
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function deleteTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $item_path = $path . '/' . $item;
        is_dir($item_path) ? deleteTree($item_path) : unlink($item_path);
    }
    rmdir($path);
}
