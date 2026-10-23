#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\ClaudeSubscriptionAuth;
use CodexRuntime\Config;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/claude-subscription-auth-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    $fake_claude = $tmp_root . '/claude';
    file_put_contents($fake_claude, <<<'PHP'
#!/usr/bin/env php
<?php
$args = array_slice($argv, 1);
if ($args === ['auth', 'status', '--json']) {
    echo json_encode(['loggedIn' => getenv('FAKE_CLAUDE_LOGGED_IN') === '1']) . "\n";
    exit(getenv('FAKE_CLAUDE_LOGGED_IN') === '1' ? 0 : 1);
}
if ($args === ['auth', 'login', '--claudeai']) {
    if (getenv('CODEX_SID') !== false || getenv('RUNTIME_SID') !== false || getenv('CLAUDE_SID') !== false) {
        fwrite(STDERR, "Runtime session variables leaked\n");
        exit(3);
    }
    echo "If the browser didn't open, visit: https://claude.com/cai/oauth/authorize?state=test-state\n";
    echo "Paste code here if prompted > ";
    $code = trim((string) fgets(STDIN));
    if ($code !== 'oauth-code#state') {
        fwrite(STDERR, "Wrong code\n");
        exit(4);
    }
    echo "Login successful\n";
    exit(0);
}
fwrite(STDERR, "Unexpected command\n");
exit(2);
PHP);
    chmod($fake_claude, 0775);
    $auth = new ClaudeSubscriptionAuth(new Config(['claude' => [
        'bin' => $fake_claude, 'cwd' => $tmp_root, 'oauth_timeout_seconds' => 5,
    ]]));

    putenv('FAKE_CLAUDE_LOGGED_IN=1');
    assertSame(true, $auth->isLoggedIn(), 'logged-in status');
    putenv('FAKE_CLAUDE_LOGGED_IN=0');
    assertSame(false, $auth->isLoggedIn(), 'logged-out status');
    putenv('CODEX_SID=must-not-leak');
    putenv('RUNTIME_SID=must-not-leak');
    putenv('CLAUDE_SID=must-not-leak');
    $urls = [];
    $auth->beginLogin(static function (string $url) use (&$urls): void { $urls[] = $url; });
    assertSame(['https://claude.com/cai/oauth/authorize?state=test-state'], $urls, 'authorization URL');
    assertSame(true, $auth->hasPendingLogin(), 'pending flow');
    $auth->completeLogin('oauth-code#state');
    assertSame(false, $auth->hasPendingLogin(), 'completed flow');

    fwrite(STDOUT, "Claude subscription auth smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Claude subscription auth smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    putenv('FAKE_CLAUDE_LOGGED_IN');
    putenv('CODEX_SID');
    putenv('RUNTIME_SID');
    putenv('CLAUDE_SID');
    deleteTree($tmp_root);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
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
