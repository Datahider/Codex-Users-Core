#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\CodexModelCatalog;
use CodexRuntime\Config;
use CodexRuntime\JsonFileStore;
use CodexRuntime\ModelPreferenceStore;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/codex-model-selection-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    $fake_codex = $tmp_root . '/codex';
    file_put_contents($fake_codex, <<<'PHP'
#!/usr/bin/env php
<?php
if (array_slice($argv, 1) !== ['debug', 'models', '--bundled']) {
    fwrite(STDERR, "Unexpected command\n");
    exit(2);
}
echo '{"models":[{"slug":"gpt-5.6-sol"},{"slug":"gpt-6-astra"},{"slug":"gpt-6-astra"}]}' . "\n";
PHP);
    chmod($fake_codex, 0775);

    $config = new Config([
        'codex' => [
            'bin' => $fake_codex,
            'model' => 'gpt-5.6-sol',
            'cwd' => $tmp_root,
        ],
    ]);
    $store = new JsonFileStore($tmp_root . '/manager-state.json');
    $preferences = new ModelPreferenceStore($store, $config);
    $catalog = new CodexModelCatalog($config);
    $session_a = '72700000-0000-0000-0000-000000000001';
    $session_b = '72700000-0000-0000-0000-000000000002';

    assertSame(['gpt-5.6-sol', 'gpt-6-astra'], $catalog->available(), 'dynamic model catalog');
    assertSame('gpt-5.6-sol', $preferences->effectiveForRuntimeSession($session_a), 'config model default');
    $preferences->setSession($session_a, 'gpt-6-astra');
    assertSame('gpt-6-astra', $preferences->effectiveForRuntimeSession($session_a), 'session override');
    assertSame('gpt-5.6-sol', $preferences->effectiveForRuntimeSession($session_b), 'other session keeps config default');

    fwrite(STDOUT, "Model selection smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Model selection smoke failed: {$e->getMessage()}\n");
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
