#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\CodexDeviceAuth;
use CodexRuntime\Config;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/codex-device-auth-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    $fake_codex = $tmp_root . '/codex';
    file_put_contents($fake_codex, <<<'PHP'
#!/usr/bin/env php
<?php
$args = array_slice($argv, 1);
if ($args === ['login', 'status']) {
    if (getenv('FAKE_CODEX_LOGGED_IN') === '1') {
        echo "Logged in using ChatGPT\n";
        exit(0);
    }
    fwrite(STDERR, "Not logged in\n");
    exit(1);
}
if ($args === ['login', '--device-auth']) {
    if (getenv('CODEX_SID') !== false || getenv('RUNTIME_SID') !== false) {
        fwrite(STDERR, "Runtime session variables leaked\n");
        exit(3);
    }
    echo "Open \033[94mhttps://auth.openai.com/codex/device\033[0m\n";
    fwrite(STDERR, "Code: \033[94mABCD-12345\033[0m\n");
    exit(0);
}
fwrite(STDERR, "Unexpected command\n");
exit(2);
PHP);
    chmod($fake_codex, 0775);

    $config = new Config([
        'codex' => [
            'bin' => $fake_codex,
            'cwd' => $tmp_root,
            'device_auth_timeout_seconds' => 5,
        ],
    ]);
    $auth = new CodexDeviceAuth($config);

    putenv('FAKE_CODEX_LOGGED_IN=1');
    assertSame(true, $auth->isLoggedIn(), 'logged-in status');
    putenv('FAKE_CODEX_LOGGED_IN=0');
    assertSame(false, $auth->isLoggedIn(), 'logged-out status');

    putenv('CODEX_SID=must-not-leak');
    putenv('RUNTIME_SID=must-not-leak');
    $challenges = [];
    $auth->login(static function (string $url, string $code) use (&$challenges): void {
        $challenges[] = [$url, $code];
    });
    assertSame([['https://auth.openai.com/codex/device', 'ABCD-12345']], $challenges, 'device challenge');

    fwrite(STDOUT, "Codex device auth smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Codex device auth smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    putenv('FAKE_CODEX_LOGGED_IN');
    putenv('CODEX_SID');
    putenv('RUNTIME_SID');
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
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $item_path = $path . '/' . $item;
        is_dir($item_path) ? deleteTree($item_path) : unlink($item_path);
    }
    rmdir($path);
}
