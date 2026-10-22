#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\ProviderAvailability;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/codex-provider-availability-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    $fake_codex = $tmp_root . '/codex';
    $fake_claude = $tmp_root . '/claude';
    foreach ([$fake_codex, $fake_claude] as $bin) {
        file_put_contents($bin, "#!/bin/sh\nexit 0\n");
        chmod($bin, 0775);
    }
    $missing_bin = $tmp_root . '/missing-claude';
    $missing_cwd = $tmp_root . '/missing-cwd';

    $full = new ProviderAvailability(new Config([
        'provider' => ['default' => 'claude'],
        'codex' => ['bin' => $fake_codex, 'cwd' => $tmp_root],
        'claude' => ['bin' => $fake_claude, 'cwd' => $tmp_root, 'model' => 'opus', 'models' => ['opus', 'sonnet']],
    ]));
    assertSame(['codex', 'claude'], $full->knownProviders(), 'known providers');
    assertSame(['codex', 'claude'], $full->availableProviders(), 'both available');
    assertSame(null, $full->unavailableReason('codex'), 'codex available');
    assertSame(null, $full->unavailableReason('claude'), 'claude available');
    assertSame('claude', $full->defaultProvider(), 'configured default');
    assertThrows(static fn () => $full->unavailableReason('gemini'), 'unknown provider reason');

    $no_default = new ProviderAvailability(new Config([
        'codex' => ['bin' => $fake_codex, 'cwd' => $tmp_root],
    ]));
    assertSame('codex', $no_default->defaultProvider(), 'missing provider.default is codex');
    assertSame(['codex'], $no_default->availableProviders(), 'claude without section is unavailable');
    assertSame('не задан claude.models', $no_default->unavailableReason('claude'), 'claude without section reason');

    $invalid_default = new ProviderAvailability(new Config([
        'provider' => ['default' => 'gemini'],
        'codex' => ['bin' => $fake_codex, 'cwd' => $tmp_root],
    ]));
    assertThrows(static fn () => $invalid_default->defaultProvider(), 'invalid provider.default');

    $cases = [
        'claude bin missing' => [
            ['bin' => $missing_bin, 'cwd' => $tmp_root, 'model' => 'opus', 'models' => ['opus']],
            "{$missing_bin} не найден в PATH",
        ],
        'claude cwd missing' => [
            ['bin' => $fake_claude, 'cwd' => $missing_cwd, 'model' => 'opus', 'models' => ['opus']],
            "каталог {$missing_cwd} не существует",
        ],
        'claude models empty' => [
            ['bin' => $fake_claude, 'cwd' => $tmp_root, 'model' => 'opus', 'models' => []],
            'не задан claude.models',
        ],
        'claude model empty' => [
            ['bin' => $fake_claude, 'cwd' => $tmp_root, 'models' => ['opus']],
            'не задан claude.model',
        ],
        'claude model outside list' => [
            ['bin' => $fake_claude, 'cwd' => $tmp_root, 'model' => 'haiku', 'models' => ['opus']],
            'claude.model haiku не входит в claude.models',
        ],
    ];
    foreach ($cases as $label => [$claude, $reason]) {
        $availability = new ProviderAvailability(new Config([
            'codex' => ['bin' => $fake_codex, 'cwd' => $tmp_root],
            'claude' => $claude,
        ]));
        assertSame($reason, $availability->unavailableReason('claude'), $label);
        assertSame(['codex'], $availability->availableProviders(), $label . ' list');
    }

    $no_codex = new ProviderAvailability(new Config([
        'codex' => ['bin' => $tmp_root . '/missing-codex', 'cwd' => $tmp_root],
        'claude' => ['bin' => $fake_claude, 'cwd' => $tmp_root, 'model' => 'opus', 'models' => ['opus']],
    ]));
    assertSame($tmp_root . '/missing-codex не найден в PATH', $no_codex->unavailableReason('codex'), 'codex bin missing');
    assertSame(['claude'], $no_codex->availableProviders(), 'only claude available');

    $codex_cwd_missing = new ProviderAvailability(new Config([
        'codex' => ['bin' => $fake_codex, 'cwd' => $missing_cwd],
    ]));
    assertSame("каталог {$missing_cwd} не существует", $codex_cwd_missing->unavailableReason('codex'), 'codex cwd missing');

    $rechecked = new ProviderAvailability(new Config([
        'codex' => ['bin' => $fake_codex, 'cwd' => $tmp_root],
        'claude' => ['bin' => $missing_bin, 'cwd' => $tmp_root, 'model' => 'opus', 'models' => ['opus']],
    ]));
    assertSame(['codex'], $rechecked->availableProviders(), 'claude unavailable before install');
    file_put_contents($missing_bin, "#!/bin/sh\nexit 0\n");
    chmod($missing_bin, 0775);
    assertSame(['codex', 'claude'], $rechecked->availableProviders(), 'availability is not cached');

    fwrite(STDOUT, "Provider availability smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Provider availability smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    deleteTree($tmp_root);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException("Assertion failed for {$label}: exception expected");
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
