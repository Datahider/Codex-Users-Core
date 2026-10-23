#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\ActiveTurnRegistry;
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

$tmp_root = sys_get_temp_dir() . '/codex-login-command-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    $auth = new class implements CodexDeviceAuthInterface {
        public bool $logged_in = false;
        public bool $fail = false;
        public int $login_calls = 0;

        public function isLoggedIn(): bool
        {
            return $this->logged_in;
        }

        public function login(callable $challenge): void
        {
            $this->login_calls++;
            if ($this->fail) throw new RuntimeException('device flow failed');
            $challenge('https://auth.openai.com/codex/device', 'ABCD-12345');
        }
    };
    [$run, $transport] = makeWatcher($tmp_root, $auth);

    $result = $run('/login', ['chat_type' => 'private']);
    assertSame(true, $result['ok'] ?? null, 'authorized result');
    assertSame('authorized', $result['mode'] ?? null, 'authorized mode');
    assertSame(1, $auth->login_calls, 'device flow started');
    assertSame([
        "Откройте ссылку и подтвердите вход в Codex:\nhttps://auth.openai.com/codex/device\n\nКод: `ABCD-12345`\n\nКод действует 15 минут. Никому его не пересылайте.",
        'Codex авторизован.',
    ], $transport->systemMessages(), 'device flow messages');

    $transport->messages = [];
    $auth->logged_in = true;
    $result = $run('/login@mybot', ['chat_type' => 'private']);
    assertSame('already_authorized', $result['mode'] ?? null, 'already authorized mode');
    assertSame(1, $auth->login_calls, 'no second device flow');
    assertSame(['Codex уже авторизован.'], $transport->systemMessages(), 'already authorized message');

    $transport->messages = [];
    $result = $run('/login', ['chat_type' => 'supergroup']);
    assertSame(false, $result['ok'] ?? null, 'group refused');
    assertSame('private_only', $result['mode'] ?? null, 'group mode');
    assertSame(['Команда /login работает только в личном чате.'], $transport->systemMessages(), 'group message');

    $transport->messages = [];
    $result = $run('/login', []);
    assertSame(false, $result['ok'] ?? null, 'missing chat type refused');
    assertSame(['Команда /login работает только в личном чате.'], $transport->systemMessages(), 'missing chat type message');

    $transport->messages = [];
    $auth->logged_in = false;
    $auth->fail = true;
    $result = $run('/login', ['chat_type' => 'private']);
    assertSame(false, $result['ok'] ?? null, 'failure result');
    assertSame('failed', $result['mode'] ?? null, 'failure mode');
    assertSame(['Не удалось авторизовать Codex: device flow failed'], $transport->systemMessages(), 'failure message');

    fwrite(STDOUT, "Login command smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Login command smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    deleteTree($tmp_root);
}

function makeWatcher(string $root, CodexDeviceAuthInterface $auth): array
{
    $fake_codex = $root . '/codex';
    file_put_contents($fake_codex, "#!/bin/sh\nexit 0\n");
    chmod($fake_codex, 0775);
    $config = new Config([
        'storage' => ['root' => $root . '/var'],
        'codex' => ['bin' => $fake_codex, 'cwd' => $root],
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
        $config,
        new Logger($paths->logFile()),
        new CommandRepository($config),
        new ActiveTurnRegistry($paths->activeTurnFile()),
        new JsonFileStore($paths->managerStateFile()),
        $transport,
        new TransportMessageIngress(new EventRepository($config)),
        new CodexSessionCatalog(),
        new WorkerShutdownFlag($config, 'control_queue', 'shutdown_flag', $paths->workerShutdownFlagFile('control_watcher')),
        new LimitMonitor($config, $limits, $transport, $clock),
        $voice,
        null,
        null,
        $auth
    );
    $method = new ReflectionMethod(ControlWatcher::class, 'processTransportCommand');
    $run = static fn(string $text, array $meta): array => $method->invoke($watcher, [
        'type' => 'transport_command',
        'text' => $text,
        'channel_id' => 'session-1',
        'session_id' => 'session-1',
        'meta' => $meta,
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
