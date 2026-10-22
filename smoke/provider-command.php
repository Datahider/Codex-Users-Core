#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\ActiveTurnRegistry;
use CodexRuntime\CodexSessionCatalog;
use CodexRuntime\Config;
use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\ControlWatcher;
use CodexRuntime\Contracts\ClockInterface;
use CodexRuntime\Contracts\RateLimitsProviderInterface;
use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\JsonFileStore;
use CodexRuntime\LimitMonitor;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\RuntimePaths;
use CodexRuntime\TransportMessageIngress;
use CodexRuntime\Voice\VoiceCommandServiceInterface;
use CodexRuntime\WorkerShutdownFlag;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/codex-provider-command-' . bin2hex(random_bytes(4));
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
echo '{"models":[{"slug":"gpt-5.6-sol"},{"slug":"gpt-6-astra"}]}' . "\n";
PHP);
    chmod($fake_codex, 0775);
    $fake_claude = $tmp_root . '/claude';
    file_put_contents($fake_claude, "#!/bin/sh\nexit 0\n");
    chmod($fake_claude, 0775);

    $codex_section = ['bin' => $fake_codex, 'model' => 'gpt-5.6-sol', 'cwd' => $tmp_root];
    $claude_section = ['bin' => $fake_claude, 'model' => 'opus', 'models' => ['opus', 'sonnet'], 'cwd' => $tmp_root];

    // Оба провайдера доступны, default — codex.
    [$run, $transport, $limits, $state] = makeWatcher($tmp_root . '/both', [
        'codex' => $codex_section,
        'claude' => $claude_section,
    ]);
    $session = 'tg-1:topic-1';
    $provider_list = "`/provider codex` (default)\n`/provider claude`";

    $result = $run($session, '/provider');
    assertSame('show', $result['mode'] ?? null, '/provider show mode');
    assertSame("Текущий провайдер: codex.\n\n{$provider_list}", lastSystem($transport), '/provider show text');

    $run($session, '/model gpt-6-astra');
    $result = $run($session, '/provider claude');
    assertSame('set', $result['mode'] ?? null, '/provider set mode');
    assertSame('claude', $result['provider'] ?? null, '/provider set result');
    assertSame('Провайдер текущей сессии: claude.', lastSystem($transport), '/provider set text');
    assertSame('claude', $state->read()['session_providers'][$session] ?? null, 'provider stored');
    assertSame(false, isset($state->read()['session_models'][$session]), 'model override dropped on provider change');

    $run($session, '/model');
    assertSame("Текущая модель: opus.\n\n`/model opus` (default)\n`/model sonnet`", lastSystem($transport), 'claude model list');
    $run($session, '/model sonnet');
    assertSame('Модель текущей сессии: sonnet.', lastSystem($transport), 'claude model set');
    $run($session, '/model gpt-6-astra');
    assertContains('Недоступная модель: gpt-6-astra', lastSystem($transport), 'codex model rejected for claude');

    $run($session, '/provider claude');
    assertSame('sonnet', $state->read()['session_models'][$session] ?? null, 'same provider keeps model override');

    $run($session, '/provider codex');
    assertSame(false, isset($state->read()['session_models'][$session]), 'switch back drops claude model');
    $run($session, '/model');
    assertContains('Текущая модель: gpt-5.6-sol.', lastSystem($transport), 'codex default model after switch back');

    $run($session, '/provider@mybot claude');
    assertSame('Провайдер текущей сессии: claude.', lastSystem($transport), '/provider@bot form');

    $result = $run($session, '/provider gemini');
    assertSame(false, $result['ok'] ?? null, 'unknown provider result');
    assertSame("Неизвестный провайдер: gemini\n\n{$provider_list}", lastSystem($transport), 'unknown provider text');
    assertSame('claude', $state->read()['session_providers'][$session] ?? null, 'unknown provider changes nothing');

    // /session и /limits для claude.
    $limits->reads = 0;
    $run($session, '/limits');
    assertSame('Лимиты claude пока неизвестны: они приходят вместе с ответом claude.', lastSystem($transport), '/limits for claude');
    assertSame(0, $limits->reads, '/limits for claude does not read codex limits');

    $run($session, '/session');
    assertSame('Список сессий для провайдера claude не поддерживается.', lastSystem($transport), '/session list for claude');

    $run($session, '/session claude-session-1');
    assertSame('claude-session-1', $state->read()['sessions'][$session] ?? null, '/session id for claude stored');
    assertSame('claude', $state->read()['session_providers'][$session] ?? null, '/session keeps claude provider');

    // Начатая сессия: смена провайдера запрещена.
    $result = $run($session, '/provider codex');
    assertSame(false, $result['ok'] ?? null, 'started session refuses provider change');
    assertSame('Сессия уже начата с провайдером claude. Сменить провайдер можно после /reset.', lastSystem($transport), 'started session text');
    assertSame('claude', $state->read()['session_providers'][$session] ?? null, 'started session provider kept');

    $run($session, '/reset');
    assertSame('claude', $state->read()['session_providers'][$session] ?? null, '/reset keeps provider');
    $run($session, '/provider codex');
    assertSame('Провайдер текущей сессии: codex.', lastSystem($transport), 'provider change after /reset');

    // /session в новой сессии записывает эффективный провайдер.
    $fresh = 'tg-1:topic-2';
    $run($fresh, '/session codex-session-9');
    assertSame('codex', $state->read()['session_providers'][$fresh] ?? null, '/session stores default provider');

    // Legacy-сессия без провайдера — codex.
    $legacy = 'tg-1:legacy';
    $state->update(static function (array $data) use ($legacy): array {
        $data['sessions'][$legacy] = 'codex-legacy';
        return $data;
    });
    $run($legacy, '/provider');
    assertSame("Текущий провайдер: codex.\n\n{$provider_list}", lastSystem($transport), 'legacy session shows codex');
    $run($legacy, '/provider claude');
    assertSame('Сессия уже начата с провайдером codex. Сменить провайдер можно после /reset.', lastSystem($transport), 'legacy session refuses change');

    // claude не установлен.
    $missing_claude = $tmp_root . '/missing-claude';
    [$run, $transport] = makeWatcher($tmp_root . '/no-claude', [
        'codex' => $codex_section,
        'claude' => ['bin' => $missing_claude] + $claude_section,
    ]);
    $run($session, '/provider');
    assertSame("Текущий провайдер: codex.\n\n`/provider codex` (default)", lastSystem($transport), 'list shows only available');
    $result = $run($session, '/provider claude');
    assertSame(false, $result['ok'] ?? null, 'unavailable provider result');
    assertSame("Провайдер claude недоступен: {$missing_claude} не найден в PATH.", lastSystem($transport), 'unavailable provider text');

    // codex не установлен, default — claude.
    $missing_codex = $tmp_root . '/missing-codex';
    [$run, $transport, $limits, $state] = makeWatcher($tmp_root . '/no-codex', [
        'provider' => ['default' => 'claude'],
        'codex' => ['bin' => $missing_codex] + $codex_section,
        'claude' => $claude_section,
    ]);
    $run($session, '/provider');
    assertSame("Текущий провайдер: claude.\n\n`/provider claude` (default)", lastSystem($transport), 'claude default list');
    $run($session, '/provider codex');
    assertSame("Провайдер codex недоступен: {$missing_codex} не найден в PATH.", lastSystem($transport), 'codex unavailable text');

    $state->update(static function (array $data) use ($legacy): array {
        $data['sessions'][$legacy] = 'codex-legacy';
        return $data;
    });
    $run($legacy, '/limits');
    assertSame("Провайдер codex недоступен: {$missing_codex} не найден в PATH.", lastSystem($transport), '/limits without codex');
    $run($legacy, '/model');
    assertSame("Провайдер codex недоступен: {$missing_codex} не найден в PATH.", lastSystem($transport), '/model for codex without codex');
    $run($legacy, '/session');
    assertSame("Провайдер codex недоступен: {$missing_codex} не найден в PATH.", lastSystem($transport), '/session list without codex');

    fwrite(STDOUT, "Provider command smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Provider command smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    deleteTree($tmp_root);
}

/**
 * @param array<string, mixed> $sections
 * @return array{0: callable, 1: object, 2: object, 3: JsonFileStore}
 */
function makeWatcher(string $root, array $sections): array
{
    mkdir($root, 0775, true);
    $config = new Config($sections + ['storage' => ['root' => $root . '/var']]);
    $paths = new RuntimePaths($config);
    $transport = new class implements TransportClientInterface {
        public array $messages = [];

        public function sendMessage(
            int|string $chatId,
            string $text,
            ?int $replyToMessageId = null,
            ?string $parseMode = null,
            bool $disableNotification = false
        ): array {
            $this->messages[] = ['kind' => $disableNotification ? 'commentary' : 'final', 'text' => $text];

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
    $limits = new class implements RateLimitsProviderInterface {
        public int $reads = 0;

        public function read(): array
        {
            $this->reads++;

            return [
                'primary' => ['usedPercent' => 0, 'resetsAt' => 1789128302],
                'secondary' => ['usedPercent' => 0, 'resetsAt' => 1789458314],
            ];
        }
    };
    $clock = new class implements ClockInterface {
        public function now(): int
        {
            return 1789110000;
        }
    };
    $voice_commands = new class implements VoiceCommandServiceInterface {
        public function handle(string $runtime_session_id, string $command): string
        {
            throw new RuntimeException('Unexpected voice command');
        }
    };
    $state = new JsonFileStore($paths->managerStateFile());
    $watcher = new ControlWatcher(
        $config,
        new Logger($paths->logFile()),
        new CommandRepository($config),
        new ActiveTurnRegistry($paths->activeTurnFile()),
        $state,
        $transport,
        new TransportMessageIngress(new EventRepository($config)),
        new CodexSessionCatalog(),
        new WorkerShutdownFlag($config, 'control_queue', 'shutdown_flag', $paths->workerShutdownFlagFile('control_watcher')),
        new LimitMonitor($config, $limits, $transport, $clock),
        $voice_commands
    );
    $process = new ReflectionMethod(ControlWatcher::class, 'processTransportCommand');
    $run = static function (string $session_id, string $text) use ($process, $watcher): array {
        return $process->invoke($watcher, [
            'type' => 'transport_command',
            'text' => $text,
            'channel_id' => $session_id,
            'session_id' => $session_id,
        ]);
    };

    return [$run, $transport, $limits, $state];
}

function lastSystem(object $transport): string
{
    $messages = array_values(array_filter($transport->messages, static fn (array $m): bool => $m['kind'] === 'system'));
    if ($messages === []) {
        throw new RuntimeException('No system message sent');
    }

    return (string) $messages[count($messages) - 1]['text'];
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("Assertion failed for {$label}: missing {$needle} in {$haystack}");
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
