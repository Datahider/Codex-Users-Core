#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\ActiveTurnRegistry;
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
use CodexRuntime\Router\TransportEnrollmentServiceInterface;
use CodexRuntime\RuntimePaths;
use CodexRuntime\TransportMessageIngress;
use CodexRuntime\Voice\VoiceCommandServiceInterface;
use CodexRuntime\WorkerShutdownFlag;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/onec-enrollment-command-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    $config = new Config(['storage' => ['root' => $tmp_root . '/var']]);
    $paths = new RuntimePaths($config);
    $transport = new class implements TransportClientInterface {
        public array $messages = [];
        public function sendMessage(int|string $chatId, string $text, ?int $replyToMessageId = null, ?string $parseMode = null, bool $disableNotification = false): array { return []; }
        public function sendTranscript(int|string $chatId, string $text): array { return []; }
        public function sendWarning(int|string $chatId, string $text): array { return []; }
        public function sendSystem(int|string $chatId, string $text): array { $this->messages[] = $text; return []; }
        public function sendChatAction(int|string $chatId, string $action = 'typing'): void {}
    };
    $enrollment = new class implements TransportEnrollmentServiceInterface {
        public int $calls = 0;
        public function createOnecCode(): array
        {
            $this->calls++;
            return ['enrollment_code' => '7KQW-9MTR-X5NP-3YZA', 'expires_at' => '2030-01-01T13:00:00+00:00'];
        }
    };
    $limits = new class implements RateLimitsProviderInterface { public function read(): array { return []; } };
    $clock = new class implements ClockInterface { public function now(): int { return 0; } };
    $voice = new class implements VoiceCommandServiceInterface { public function handle(string $runtime_session_id, string $command): string { return ''; } };
    $watcher = new ControlWatcher(
        $config, new Logger($paths->logFile()), new CommandRepository($config),
        new ActiveTurnRegistry($paths->activeTurnFile()), new JsonFileStore($paths->managerStateFile()),
        $transport, new TransportMessageIngress(new EventRepository($config)), new CodexSessionCatalog(),
        new WorkerShutdownFlag($config, 'control_queue', 'shutdown_flag', $paths->workerShutdownFlagFile('control_watcher')),
        new LimitMonitor($config, $limits, $transport, $clock), $voice,
        null, null, null, null, $enrollment
    );
    $method = new ReflectionMethod(ControlWatcher::class, 'processTransportCommand');
    $run = static fn (string $text, string $chat_type): array => $method->invoke($watcher, [
        'type' => 'transport_command',
        'text' => $text,
        'channel_id' => 'session-1',
        'session_id' => 'session-1',
        'meta' => ['chat_type' => $chat_type],
    ]);

    $result = $run('/1c', 'private');
    assertSame(true, $result['ok'] ?? null, 'private command result');
    assertSame(1, $enrollment->calls, 'Router enrollment call count');
    assertSame([
        "Код подключения 1С: `7KQW-9MTR-X5NP-3YZA`\n\nВведите его в настройках расширения «Гоша ИИ». Код действует до 16:00:00 01.01.2030 (МСК).",
    ], $transport->messages, 'private response');

    $transport->messages = [];
    $result = $run('/1c now', 'private');
    assertSame(false, $result['ok'] ?? null, 'argument form result');
    assertSame(['Команда /1c не принимает аргументов.'], $transport->messages, 'argument form response');

    $transport->messages = [];
    $result = $run('/1c', 'supergroup');
    assertSame(false, $result['ok'] ?? null, 'group result');
    assertSame(['Команда /1c работает только в личном чате.'], $transport->messages, 'group response');

    fwrite(STDOUT, "1C enrollment command smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "1C enrollment command smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
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
