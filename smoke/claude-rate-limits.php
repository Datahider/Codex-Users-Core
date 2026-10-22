#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\ActiveTurnRegistry;
use CodexRuntime\ClaudeProcess;
use CodexRuntime\Config;
use CodexRuntime\Contracts\ClockInterface;
use CodexRuntime\Contracts\RateLimitsProviderInterface;
use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\JsonFileStore;
use CodexRuntime\LimitMonitor;
use CodexRuntime\Logger;
use CodexRuntime\RuntimePaths;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/codex-claude-rate-limits-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    $fake_claude = $tmp_root . '/claude';
    file_put_contents($fake_claude, <<<'PHP'
#!/usr/bin/env php
<?php
stream_get_contents(STDIN);
$emit = static fn (array $event) => fwrite(STDOUT, json_encode($event) . "\n");
$emit(['type' => 'system', 'subtype' => 'init', 'session_id' => 'claude-sess-1']);
$utilization = getenv('FAKE_CLAUDE_LIMITS') === 'invalid' ? 'x' : 0.14;
$emit(['type' => 'rate_limit_event', 'rate_limit_info' => [
    'status' => 'allowed',
    'unifiedWindows' => [
        'five_hour' => ['utilization' => $utilization, 'resetsAt' => 1792683000],
        'seven_day' => ['utilization' => 0.02, 'resetsAt' => 1792886400],
    ],
], 'session_id' => 'claude-sess-1']);
$emit(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'ok', 'session_id' => 'claude-sess-1']);
PHP);
    chmod($fake_claude, 0775);

    $config = new Config([
        'claude' => ['bin' => $fake_claude, 'model' => 'opus', 'models' => ['opus'], 'cwd' => $tmp_root],
        'storage' => ['root' => $tmp_root . '/var'],
        'limits' => ['primary_remaining_warning_percent' => 90, 'secondary_remaining_warning_percent' => 1],
    ]);
    $paths = new RuntimePaths($config);
    $limits_file = $paths->stateDir() . '/claude-rate-limits.json';
    $process = new ClaudeProcess($config, new Logger($tmp_root . '/runtime.log'), new ActiveTurnRegistry($tmp_root . '/active-turn.json'));

    // ClaudeProcess сохраняет лимиты из rate_limit_event.
    $before = time();
    $result = $process->run('Привет', null, null, static function (): void {}, 'tg-1-a', 'opus');
    $after = time();
    assertSame(0, $result['exit_code'] ?? null, 'turn with rate_limit_event succeeds');
    $saved = json_decode((string) @file_get_contents($limits_file), true);
    assertSame(['usedPercent' => 14, 'resetsAt' => 1792683000], $saved['primary'] ?? null, 'five_hour saved as primary');
    assertSame(['usedPercent' => 2, 'resetsAt' => 1792886400], $saved['secondary'] ?? null, 'seven_day saved as secondary');
    assertSame(true, is_int($saved['observed_at'] ?? null) && $saved['observed_at'] >= $before && $saved['observed_at'] <= $after, 'observed_at is turn time');

    // Невалидное событие не перезаписывает файл и не ломает turn.
    $previous = file_get_contents($limits_file);
    putenv('FAKE_CLAUDE_LIMITS=invalid');
    $result = $process->run('Привет', null, null, static function (): void {}, 'tg-1-a', 'opus');
    putenv('FAKE_CLAUDE_LIMITS');
    assertSame(0, $result['exit_code'] ?? null, 'invalid rate_limit_event does not fail turn');
    assertSame('ok', $result['text'] ?? null, 'invalid rate_limit_event keeps final');
    assertSame($previous, file_get_contents($limits_file), 'invalid rate_limit_event discarded');

    // LimitMonitor для claude-сессии.
    $now = 1792680000;
    $state = new JsonFileStore($paths->managerStateFile());
    $state->write([
        'sessions' => ['tg-1-claude' => 'claude-sess-1'],
        'session_providers' => ['tg-1-claude' => 'claude', 'tg-1-codex' => 'codex'],
    ]);
    file_put_contents($limits_file, json_encode([
        'observed_at' => $now - 300,
        'primary' => ['usedPercent' => 14, 'resetsAt' => 1792683000],
        'secondary' => ['usedPercent' => 2, 'resetsAt' => 1792886400],
    ]));

    $transport = new class implements TransportClientInterface {
        public array $messages = [];

        public function sendMessage(
            int|string $chatId,
            string $text,
            ?int $replyToMessageId = null,
            ?string $parseMode = null,
            bool $disableNotification = false
        ): array {
            $this->messages[] = ['kind' => 'final', 'text' => $text];

            return ['message_id' => count($this->messages)];
        }

        public function sendWarning(int|string $chatId, string $text): array
        {
            $this->messages[] = ['kind' => 'warning', 'text' => $text];

            return ['message_id' => count($this->messages)];
        }

        public function sendSystem(int|string $chatId, string $text): array
        {
            $this->messages[] = ['kind' => 'system', 'text' => $text];

            return ['message_id' => count($this->messages)];
        }

        public function sendTranscript(int|string $chatId, string $text): array
        {
            throw new RuntimeException('Unexpected transcript');
        }

        public function sendChatAction(int|string $chatId, string $action = 'typing'): void
        {
        }
    };
    $codex_limits = new class implements RateLimitsProviderInterface {
        public int $reads = 0;

        public function read(): array
        {
            $this->reads++;

            return [
                'primary' => ['usedPercent' => 50, 'resetsAt' => 1792683000],
                'secondary' => ['usedPercent' => 0, 'resetsAt' => 1792886400],
            ];
        }
    };
    $clock = new class($now) implements ClockInterface {
        public function __construct(private int $now)
        {
        }

        public function now(): int
        {
            return $this->now;
        }
    };
    $monitor = new LimitMonitor($config, $codex_limits, $transport, $clock);

    $primary_window = "5 часов\n`█████████████████░░░` 86%\nСброс через 50 мин";
    $secondary_window = "7 дней\n`████████████████████` 98%\nСброс через 2 д 9 ч 20 мин";

    $monitor->sendCurrentLimits('tg-1-claude');
    assertSame(
        [['kind' => 'system', 'text' => "Лимиты Claude\n\n{$primary_window}\n\n{$secondary_window}\n\nПолучено 5 мин назад"]],
        $transport->messages,
        '/limits for claude session'
    );
    assertSame(0, $codex_limits->reads, 'codex limits not read for claude /limits');

    $transport->messages = [];
    $monitor->sendFinal('tg-1-claude', 'Готово.');
    assertSame(
        [
            ['kind' => 'final', 'text' => 'Готово.'],
            ['kind' => 'warning', 'text' => "Внимание: заканчиваются лимиты Claude.\n\n{$primary_window}"],
        ],
        $transport->messages,
        'claude warning after final'
    );
    assertSame(0, $codex_limits->reads, 'codex limits not read after claude final');

    $transport->messages = [];
    $monitor->sendFinal('tg-1-codex', 'Готово.');
    assertSame(1, $codex_limits->reads, 'codex session still reads codex limits');
    assertSame('Внимание: заканчиваются лимиты Codex.', strtok((string) ($transport->messages[1]['text'] ?? ''), "\n"), 'codex warning unchanged');

    file_put_contents($limits_file, json_encode(['observed_at' => $now - 20] + json_decode((string) file_get_contents($limits_file), true)));
    $transport->messages = [];
    $monitor->sendCurrentLimits('tg-1-claude');
    assertSame(true, str_ends_with((string) ($transport->messages[0]['text'] ?? ''), "\n\nПолучено менее минуты назад"), 'fresh limits age');

    // Лимитов ещё нет.
    unlink($limits_file);
    $transport->messages = [];
    $monitor->sendCurrentLimits('tg-1-claude');
    assertSame(
        [['kind' => 'system', 'text' => 'Лимиты claude пока неизвестны: они приходят вместе с ответом claude.']],
        $transport->messages,
        '/limits without claude data'
    );
    $transport->messages = [];
    $monitor->sendFinal('tg-1-claude', 'Готово.');
    assertSame([['kind' => 'final', 'text' => 'Готово.']], $transport->messages, 'no warning check without claude data');

    // Повреждённый файл — ошибка.
    file_put_contents($limits_file, '{"observed_at":');
    assertThrows(static fn () => $monitor->sendCurrentLimits('tg-1-claude'), 'corrupted claude limits file');

    fwrite(STDOUT, "Claude rate limits smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Claude rate limits smoke failed: {$e->getMessage()}\n");
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
    } catch (Throwable) {
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
