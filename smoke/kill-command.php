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
use CodexRuntime\RuntimePaths;
use CodexRuntime\TransportMessageIngress;
use CodexRuntime\Voice\VoiceCommandServiceInterface;
use CodexRuntime\WorkerShutdownFlag;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/kill-command-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);
$victim = null;

try {
    [$run, $transport, $paths] = makeWatcher($tmp_root);

    // A stand-in for the main Core process.
    $victim = proc_open(['sleep', '60'], [], $pipes);
    $victim_pid = proc_get_status($victim)['pid'];
    mkdir(dirname($paths->mainPidFile()), 0775, true);
    file_put_contents($paths->mainPidFile(), (string) $victim_pid);

    $result = $run('/kill now');
    assertSame(true, posix_kill($victim_pid, 0), 'argument form does not kill');
    assertSame(['Команда /kill не принимает аргументов.'], $transport->systemMessages(), 'argument form message');
    assertSame(true, $result['ok'] ?? null, 'argument form handled as command');

    $transport->messages = [];
    $result = $run('/kill@nika_bot');
    $status = waitExit($victim);
    assertSame(true, $status['signaled'], 'main process killed');
    assertSame(SIGKILL, $status['termsig'], 'killed with SIGKILL');
    assertSame(['Ядро завершается по /kill. systemd перезапустит его через несколько секунд.'], $transport->systemMessages(), 'kill message');
    assertSame(true, $result['ok'] ?? null, 'kill result ok');

    $transport->messages = [];
    unlink($paths->mainPidFile());
    $result = $run('/kill');
    assertSame(false, $result['ok'] ?? null, 'missing pid file fails');
    assertSame(1, count($transport->systemMessages()), 'one failure message');
    assertSame(true, str_starts_with($transport->systemMessages()[0], 'Не удалось завершить ядро: '), 'failure message prefix');

    $transport->messages = [];
    file_put_contents($paths->mainPidFile(), 'garbage');
    $result = $run('/kill');
    assertSame(false, $result['ok'] ?? null, 'garbage pid file fails');

    fwrite(STDOUT, "Kill command smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Kill command smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    if (is_resource($victim) && proc_get_status($victim)['running']) {
        proc_terminate($victim, SIGKILL);
    }
    deleteTree($tmp_root);
}

function waitExit($process): array
{
    for ($i = 0; $i < 50; $i++) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            return $status;
        }
        usleep(100000);
    }
    throw new RuntimeException('main process stand-in is still running');
}

function makeWatcher(string $root): array
{
    $config = new Config([
        'storage' => ['root' => $root . '/var'],
        'codex' => ['bin' => $root . '/codex', 'cwd' => $root],
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
        new LimitMonitor($config, $limits, $transport, $clock), $voice
    );
    $method = new ReflectionMethod(ControlWatcher::class, 'processTransportCommand');
    $run = static fn(string $text): array => $method->invoke($watcher, [
        'type' => 'transport_command', 'text' => $text, 'channel_id' => 'session-1', 'session_id' => 'session-1', 'meta' => [],
    ]);
    return [$run, $transport, $paths];
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
