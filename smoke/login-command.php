#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\ActiveTurnRegistry;
use CodexRuntime\ClaudeSubscriptionAuthInterface;
use CodexRuntime\CodexDeviceAuthInterface;
use CodexRuntime\CodexSessionCatalog;
use CodexRuntime\Config;
use CodexRuntime\Contracts\ClockInterface;
use CodexRuntime\Contracts\RateLimitsProviderInterface;
use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\ControlWatcher;
use CodexRuntime\JsonFileStore;
use CodexRuntime\LimitMonitor;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\RuntimePaths;
use CodexRuntime\TransportMessageIngress;
use CodexRuntime\Voice\VoiceCommandServiceInterface;
use CodexRuntime\WorkerShutdownFlag;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/provider-login-command-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    foreach (['codex', 'claude'] as $bin) {
        file_put_contents($tmp_root . '/' . $bin, "#!/bin/sh\nexit 0\n");
        chmod($tmp_root . '/' . $bin, 0775);
    }
    $codex_auth = new class implements CodexDeviceAuthInterface {
        public bool $logged_in = false;
        public int $login_calls = 0;
        public function isLoggedIn(): bool { return $this->logged_in; }
        public function login(callable $challenge): void
        {
            $this->login_calls++;
            $challenge('https://auth.openai.com/codex/device', 'ABCD-12345');
        }
    };
    $claude_auth = new class implements ClaudeSubscriptionAuthInterface {
        public bool $logged_in = false;
        public bool $pending = false;
        public int $begin_calls = 0;
        public array $codes = [];
        public function isLoggedIn(): bool { return $this->logged_in; }
        public function hasPendingLogin(): bool { return $this->pending; }
        public function beginLogin(callable $authorization): void
        {
            $this->begin_calls++;
            $this->pending = true;
            $authorization('https://claude.com/cai/oauth/authorize?state=test');
        }
        public function completeLogin(string $code): void
        {
            if (!$this->pending) throw new RuntimeException('Claude OAuth flow is not pending');
            $this->codes[] = $code;
            $this->pending = false;
            $this->logged_in = true;
        }
    };
    [$run, $transport] = makeWatcher($tmp_root, $codex_auth, $claude_auth);

    $result = $run('/login', ['chat_type' => 'private']);
    assertSame('status', $result['mode'] ?? null, 'status mode');
    assertSame(["Авторизация:\nCodex: не авторизован.\nClaude: не авторизован.\n\nКоманды:\n`/login codex`\n`/login claude`"], $transport->systemMessages(), 'status message');

    $transport->messages = [];
    $result = $run('/login codex', ['chat_type' => 'private']);
    assertSame('authorized', $result['mode'] ?? null, 'codex authorized mode');
    assertSame(1, $codex_auth->login_calls, 'codex flow started');
    assertSame([
        "Откройте ссылку и подтвердите вход в Codex:\nhttps://auth.openai.com/codex/device\n\nКод: `ABCD-12345`\n\nКод действует 15 минут. Никому его не пересылайте.",
        'Codex авторизован.',
    ], $transport->systemMessages(), 'codex flow messages');

    $transport->messages = [];
    $result = $run('/login claude', ['chat_type' => 'private']);
    assertSame('awaiting_code', $result['mode'] ?? null, 'claude awaiting code mode');
    assertSame(1, $claude_auth->begin_calls, 'claude flow started');
    assertSame(["Откройте ссылку и войдите в Claude Pro:\nhttps://claude.com/cai/oauth/authorize?state=test\n\nПосле входа пришлите полученный код командой:\n`/login claude <код>`"], $transport->systemMessages(), 'claude authorization message');

    $transport->messages = [];
    $result = $run('/login@mybot claude oauth-code#state', ['chat_type' => 'private']);
    assertSame('authorized', $result['mode'] ?? null, 'claude authorized mode');
    assertSame(['oauth-code#state'], $claude_auth->codes, 'claude code passed');
    assertSame(['Claude авторизован через Claude Pro.'], $transport->systemMessages(), 'claude completed message');

    $transport->messages = [];
    $codex_auth->logged_in = true;
    $result = $run('/login codex', ['chat_type' => 'private']);
    assertSame('already_authorized', $result['mode'] ?? null, 'codex already authorized mode');
    assertSame(['Codex уже авторизован.'], $transport->systemMessages(), 'codex already authorized message');

    $transport->messages = [];
    $result = $run('/login claude', ['chat_type' => 'private']);
    assertSame('already_authorized', $result['mode'] ?? null, 'claude already authorized mode');
    assertSame(['Claude уже авторизован через Claude Pro.'], $transport->systemMessages(), 'claude already authorized message');

    $transport->messages = [];
    $result = $run('/login', ['chat_type' => 'supergroup']);
    assertSame(false, $result['ok'] ?? null, 'group refused');
    assertSame(['Команда /login работает только в личном чате.'], $transport->systemMessages(), 'group message');

    $transport->messages = [];
    $result = $run('/login other', ['chat_type' => 'private']);
    assertSame(false, $result['ok'] ?? null, 'unknown provider refused');
    assertSame(['Неизвестный провайдер: other. Используйте `/login codex` или `/login claude`.'], $transport->systemMessages(), 'unknown provider message');

    fwrite(STDOUT, "Login command smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Login command smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    deleteTree($tmp_root);
}

function makeWatcher(string $root, CodexDeviceAuthInterface $codex_auth, ClaudeSubscriptionAuthInterface $claude_auth): array
{
    $config = new Config([
        'storage' => ['root' => $root . '/var'],
        'codex' => ['bin' => $root . '/codex', 'cwd' => $root],
        'claude' => ['bin' => $root . '/claude', 'cwd' => $root, 'model' => 'opus', 'models' => ['opus']],
    ]);
    $paths = new RuntimePaths($config);
    $transport = new class implements TransportClientInterface {
        public array $messages = [];
        public function sendMessage(int|string $chatId, string $text, ?int $replyToMessageId = null, ?string $parseMode = null, bool $disableNotification = false): array { return []; }
        public function sendTranscript(int|string $chatId, string $text): array { return []; }
        public function sendWarning(int|string $chatId, string $text): array { return []; }
        public function sendSystem(int|string $chatId, string $text): array { $this->messages[] = $text; return []; }
        public function sendChatAction(int|string $chatId, string $action = 'typing'): void {}
        public function systemMessages(): array { return $this->messages; }
    };
    $limits = new class implements RateLimitsProviderInterface { public function read(): array { return []; } };
    $clock = new class implements ClockInterface { public function now(): int { return 0; } };
    $voice = new class implements VoiceCommandServiceInterface { public function handle(string $runtime_session_id, string $command): string { return ''; } };
    $watcher = new ControlWatcher(
        $config, new Logger($paths->logFile()), new CommandRepository($config),
        new ActiveTurnRegistry($paths->activeTurnFile()), new JsonFileStore($paths->managerStateFile()),
        $transport, new TransportMessageIngress(new EventRepository($config)), new CodexSessionCatalog(),
        new WorkerShutdownFlag($config, 'control_queue', 'shutdown_flag', $paths->workerShutdownFlagFile('control_watcher')),
        new LimitMonitor($config, $limits, $transport, $clock), $voice, null, null, $codex_auth, $claude_auth
    );
    $method = new ReflectionMethod(ControlWatcher::class, 'processTransportCommand');
    $run = static fn(string $text, array $meta): array => $method->invoke($watcher, [
        'type' => 'transport_command', 'text' => $text, 'channel_id' => 'session-1', 'session_id' => 'session-1', 'meta' => $meta,
    ]);
    return [$run, $transport];
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
