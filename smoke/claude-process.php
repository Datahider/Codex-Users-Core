#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\ActiveTurnRegistry;
use CodexRuntime\ClaudeProcess;
use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\RuntimePaths;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/codex-claude-process-' . bin2hex(random_bytes(4));
mkdir($tmp_root . '/cwd', 0775, true);
putenv('CODEX_SID');
putenv('CLAUDE_SID');

try {
    $record_file = $tmp_root . '/record.json';
    $fake_claude = $tmp_root . '/claude';
    file_put_contents($fake_claude, str_replace('__RECORD__', var_export($record_file, true), <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents(__RECORD__, json_encode([
    'argv' => array_slice($argv, 1),
    'stdin' => stream_get_contents(STDIN),
    'cwd' => getcwd(),
    'env' => [
        'RUNTIME_SID' => getenv('RUNTIME_SID'),
        'CLAUDE_SID' => getenv('CLAUDE_SID'),
        'CODEX_SID' => getenv('CODEX_SID'),
        'CODEX_CORE_CONFIG' => getenv('CODEX_CORE_CONFIG'),
        'CODEX_STORAGE_ROOT' => getenv('CODEX_STORAGE_ROOT'),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$emit = static function (array $event): void {
    fwrite(STDOUT, json_encode($event, JSON_UNESCAPED_UNICODE) . "\n");
    fflush(STDOUT);
    usleep(400000);
};

$mode = (string) getenv('FAKE_CLAUDE_MODE');
if ($mode === 'crash') {
    fwrite(STDERR, "boom\n");
    exit(3);
}

$emit(['type' => 'system', 'subtype' => 'init', 'session_id' => 'claude-sess-1']);
if ($mode === 'error') {
    $emit(['type' => 'result', 'subtype' => 'error_during_execution', 'is_error' => true, 'result' => 'API Error: overloaded', 'session_id' => 'claude-sess-1']);
    exit(0);
}
$emit(['type' => 'assistant', 'message' => ['content' => [
    ['type' => 'text', 'text' => 'Смотрю файлы'],
    ['type' => 'tool_use', 'id' => 't1', 'name' => 'Bash', 'input' => ['command' => 'ls']],
]]]);
$emit(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'a.txt']]]]);
$emit(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Готово.']]]]);
$emit(['type' => 'rate_limit_event', 'rate_limit_info' => ['status' => 'allowed']]);
$emit(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'Готово.', 'session_id' => 'claude-sess-1']);
PHP));
    chmod($fake_claude, 0775);

    $config_path = $tmp_root . '/config.php';
    file_put_contents($config_path, "<?php\nreturn [];\n");
    $config = new Config([
        'claude' => [
            'bin' => $fake_claude,
            'model' => 'opus',
            'models' => ['opus', 'sonnet'],
            'cwd' => $tmp_root . '/cwd',
            'extra_args' => ['--add-dir', '/srv'],
        ],
        'storage' => [
            'root' => $tmp_root . '/var',
        ],
        'transport' => [
            'progress_keepalive_ms' => 100000,
        ],
    ]);
    $active_turn_file = $tmp_root . '/active-turn.json';
    $process = new ClaudeProcess(
        $config,
        new Logger($tmp_root . '/runtime.log'),
        new ActiveTurnRegistry($active_turn_file),
        $config_path
    );
    $mcp_server = dirname(__DIR__) . '/bin/mcp-server.php';
    $expected_mcp = [
        'mcpServers' => [
            'codex_runtime' => [
                'command' => 'php',
                'args' => [$mcp_server],
                'env' => [
                    'RUNTIME_SID' => 'tg-1:topic-1',
                    'CODEX_CORE_CONFIG' => $config_path,
                ],
            ],
        ],
    ];

    // Новая сессия.
    $commentary = [];
    $on_progress = static function (string $partial, string $delta = '', bool $running = true) use (&$commentary): void {
        if ($delta !== '') {
            $commentary[] = $delta;
        }
    };
    $result = $process->run('Привет', null, null, $on_progress, 'tg-1:topic-1', 'sonnet');
    $record = json_decode((string) file_get_contents($record_file), true);

    $argv = $record['argv'];
    $mcp_index = array_search('--mcp-config', $argv, true);
    assertSame(true, is_int($mcp_index), 'mcp-config present');
    assertSame($expected_mcp, json_decode((string) $argv[$mcp_index + 1], true), 'mcp-config json');
    array_splice($argv, $mcp_index, 2);
    assertSame(
        ['-p', '--output-format', 'stream-json', '--verbose', '--dangerously-skip-permissions', '--add-dir', '/srv', '--model', 'sonnet'],
        $argv,
        'new session command'
    );
    assertSame('Привет', $record['stdin'], 'prompt via stdin');
    assertSame(realpath($tmp_root . '/cwd'), $record['cwd'], 'claude.cwd used');
    assertSame('tg-1:topic-1', $record['env']['RUNTIME_SID'], 'RUNTIME_SID env');
    assertSame(false, $record['env']['CLAUDE_SID'], 'no CLAUDE_SID for new session');
    assertSame(false, $record['env']['CODEX_SID'], 'no CODEX_SID for claude');
    assertSame($config_path, $record['env']['CODEX_CORE_CONFIG'], 'CODEX_CORE_CONFIG env');
    assertSame($tmp_root . '/var', $record['env']['CODEX_STORAGE_ROOT'], 'CODEX_STORAGE_ROOT env');

    assertSame('claude-sess-1', $result['session_id'] ?? null, 'session id from stream');
    assertSame('Готово.', $result['text'] ?? null, 'final text from result event');
    assertSame(0, $result['exit_code'] ?? null, 'success exit code');
    assertSame(['Смотрю файлы'], $commentary, 'only intermediate text is commentary');
    assertSame(1, count(glob((new RuntimePaths($config))->codexDebugDir() . '/claude-run-*.stdout.jsonl') ?: []), 'raw stdout logged with claude-run- prefix');
    assertSame(false, str_contains((string) @file_get_contents($active_turn_file), 'tg-1:topic-1'), 'active turn cleared');

    // Resume существующей сессии.
    $process->run('Дальше', 'claude-sess-1', null, static function (): void {}, 'tg-1:topic-1', 'opus');
    $record = json_decode((string) file_get_contents($record_file), true);
    $argv = $record['argv'];
    $mcp_index = array_search('--mcp-config', $argv, true);
    array_splice($argv, $mcp_index, 2);
    assertSame(
        ['-p', '--output-format', 'stream-json', '--verbose', '--dangerously-skip-permissions', '--add-dir', '/srv', '--model', 'opus', '--resume', 'claude-sess-1'],
        $argv,
        'resume command'
    );
    assertSame('claude-sess-1', $record['env']['CLAUDE_SID'], 'CLAUDE_SID for resume');

    // is_error=true.
    putenv('FAKE_CLAUDE_MODE=error');
    $result = $process->run('Привет', null, null, static function (): void {}, 'tg-1:topic-1', 'opus');
    assertSame('API Error: overloaded', $result['text'] ?? null, 'error result text');
    assertSame(true, ($result['exit_code'] ?? 0) !== 0, 'is_error is a failed turn');
    assertSame('claude-sess-1', $result['session_id'] ?? null, 'session id kept on error');

    // Падение без события result.
    putenv('FAKE_CLAUDE_MODE=crash');
    $result = $process->run('Привет', null, null, static function (): void {}, 'tg-1:topic-1', 'opus');
    assertSame("Claude вернул ошибку:\nboom", $result['text'] ?? null, 'crash text');
    assertSame(3, $result['exit_code'] ?? null, 'crash exit code');
    putenv('FAKE_CLAUDE_MODE');

    fwrite(STDOUT, "Claude process smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Claude process smoke failed: {$e->getMessage()}\n");
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
