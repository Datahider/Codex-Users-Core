#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\JsonFileStore;
use CodexRuntime\ModelPreferenceStore;
use CodexRuntime\ProviderPreferenceStore;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/codex-provider-selection-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    $config = new Config([
        'provider' => ['default' => 'claude'],
        'codex' => ['model' => 'gpt-5.6-sol', 'cwd' => $tmp_root],
        'claude' => ['model' => 'opus', 'models' => ['opus', 'sonnet'], 'cwd' => $tmp_root],
    ]);
    $store = new JsonFileStore($tmp_root . '/manager-state.json');
    $providers = new ProviderPreferenceStore($store, $config);
    $models = new ModelPreferenceStore($store, $config);

    $fresh = 'tg-1:fresh';
    $legacy = 'tg-1:legacy';
    $chosen = 'tg-1:chosen';

    $store->write([
        'sessions' => [$legacy => 'codex-legacy-id'],
    ]);

    assertSame(false, $providers->isStarted($fresh), 'fresh session is not started');
    assertSame('claude', $providers->effectiveForRuntimeSession($fresh), 'fresh session uses provider.default');
    assertSame(true, $providers->isStarted($legacy), 'legacy session is started');
    assertSame('codex', $providers->effectiveForRuntimeSession($legacy), 'legacy session without provider is codex');
    assertSame(['sessions' => [$legacy => 'codex-legacy-id']], $store->read(), 'legacy state is not rewritten');

    $providers->setSession($chosen, 'codex');
    assertSame('codex', $providers->effectiveForRuntimeSession($chosen), 'explicit choice beats default');
    assertSame('codex', $store->read()['session_providers'][$chosen] ?? null, 'choice stored in session_providers');
    assertThrows(static fn () => $providers->setSession($legacy, 'claude'), 'setSession refuses started session');
    assertThrows(static fn () => $providers->setSession($fresh, 'gemini'), 'setSession refuses unknown provider');

    assertSame('opus', $models->defaultModelForProvider('claude'), 'claude default model');
    assertSame('gpt-5.6-sol', $models->defaultModelForProvider('codex'), 'codex default model');
    assertSame('opus', $models->effectiveForRuntimeSession($fresh), 'fresh claude session model');
    assertSame('gpt-5.6-sol', $models->effectiveForRuntimeSession($legacy), 'legacy codex session model');
    assertSame('gpt-5.6-sol', $models->effectiveForRuntimeSession($chosen), 'chosen codex session model');

    $models->setSession($fresh, 'sonnet');
    assertSame('sonnet', $models->effectiveForRuntimeSession($fresh), 'claude model override');
    $models->clearSession($fresh);
    assertSame('opus', $models->effectiveForRuntimeSession($fresh), 'cleared override returns to provider default');

    $codex_only = new ModelPreferenceStore(
        new JsonFileStore($tmp_root . '/codex-only-state.json'),
        new Config(['codex' => ['cwd' => $tmp_root]])
    );
    assertSame('gpt-5.6-sol', $codex_only->defaultModel(), 'existing codex fallback model');
    assertSame('gpt-5.6-sol', $codex_only->effectiveForRuntimeSession($fresh), 'default provider codex without provider section');

    fwrite(STDOUT, "Provider selection smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Provider selection smoke failed: {$e->getMessage()}\n");
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
