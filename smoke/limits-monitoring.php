#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\CodexAppServerRateLimitsProvider;
use CodexRuntime\ActiveTurnRegistry;
use CodexRuntime\CodexSessionCatalog;
use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\ControlWatcher;
use CodexRuntime\Contracts\RateLimitsProviderInterface;
use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\JsonFileStore;
use CodexRuntime\LimitMonitor;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\RuntimePaths;
use CodexRuntime\TransportMessageIngress;
use CodexRuntime\WorkerShutdownFlag;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $tmp_root = sys_get_temp_dir() . '/codex-limits-' . bin2hex(random_bytes(4));
    mkdir($tmp_root, 0775, true);
    $fake_codex = $tmp_root . '/codex';
    file_put_contents($fake_codex, <<<'PHP'
#!/usr/bin/env php
<?php
$initialize = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($initialize['method'] ?? null) !== 'initialize') {
    exit(2);
}
fwrite(STDOUT, "{\"id\":0,\"result\":{\"userAgent\":\"fake\"}}\n");
fflush(STDOUT);
$initialized = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$request = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($initialized['method'] ?? null) !== 'initialized' || ($request['method'] ?? null) !== 'account/rateLimits/read') {
    exit(3);
}
fwrite(STDOUT, '{"id":1,"result":{"rateLimits":{"planType":"plus","primary":{"usedPercent":85,"resetsAt":1789128302},"secondary":{"usedPercent":89,"resetsAt":1789458314}}}}' . "\n");
fflush(STDOUT);
PHP);
    chmod($fake_codex, 0775);

    $config = new Config([
        'codex' => [
            'bin' => $fake_codex,
            'cwd' => $tmp_root,
        ],
        'limits' => [
            'primary_remaining_warning_percent' => 20,
            'secondary_remaining_warning_percent' => 10,
            'timezone' => 'Europe/Moscow',
        ],
        'storage' => [
            'root' => $tmp_root . '/var',
        ],
    ]);

    $app_server_provider = new CodexAppServerRateLimitsProvider($config);
    $app_server_limits = $app_server_provider->read();
    assertSame(85, $app_server_limits['primary']['usedPercent'] ?? null, 'app-server primary usage');

    $provider = new class implements RateLimitsProviderInterface {
        public int $reads = 0;

        public function read(): array
        {
            $this->reads++;

            return [
                'planType' => 'plus',
                'primary' => [
                    'usedPercent' => 85,
                    'windowDurationMins' => 300,
                    'resetsAt' => 1789128302,
                ],
                'secondary' => [
                    'usedPercent' => 89,
                    'windowDurationMins' => 10080,
                    'resetsAt' => 1789458314,
                ],
            ];
        }
    };

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

        public function sendTranscript(int|string $chatId, string $text): array
        {
            throw new RuntimeException('Unexpected transcript');
        }

        public function sendChatAction(int|string $chatId, string $action = 'typing'): void
        {
        }
    };

    $monitor = new LimitMonitor($config, $provider, $transport);
    $monitor->sendCurrentLimits('runtime-42');

    assertSame(1, $provider->reads, 'limits reads for /limits');
    assertSame('final', $transport->messages[0]['kind'] ?? null, '/limits response kind');
    assertContains('5 часов: осталось 15%', $transport->messages[0]['text'] ?? '', 'primary status');
    assertContains('7 дней: осталось 11%', $transport->messages[0]['text'] ?? '', 'secondary status');
    assertContains('Тариф: Plus', $transport->messages[0]['text'] ?? '', 'plan status');
    assertSame('warning', $transport->messages[1]['kind'] ?? null, '/limits warning kind');
    assertContains('5 часов: осталось 15%', $transport->messages[1]['text'] ?? '', 'primary warning');
    assertNotContains('7 дней', $transport->messages[1]['text'] ?? '', 'secondary threshold is strict');

    $transport->messages = [];
    $monitor->sendFinal('runtime-42', 'Готово.');

    assertSame(2, $provider->reads, 'limits reads after final');
    assertSame('final', $transport->messages[0]['kind'] ?? null, 'normal final kind');
    assertSame('warning', $transport->messages[1]['kind'] ?? null, 'automatic warning kind');

    $transport->messages = [];
    $paths = new RuntimePaths($config);
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
        $monitor
    );
    $process_command = new ReflectionMethod(ControlWatcher::class, 'processTransportCommand');
    $command_result = $process_command->invoke($watcher, [
        'type' => 'transport_command',
        'text' => '/limits',
        'channel_id' => 'runtime-42',
        'session_id' => 'runtime-42',
    ]);
    assertSame(true, $command_result['ok'] ?? null, '/limits command result');
    assertSame('final', $transport->messages[0]['kind'] ?? null, '/limits command final');
    assertSame('warning', $transport->messages[1]['kind'] ?? null, '/limits command warning');

    fwrite(STDOUT, "Limits monitoring smoke: OK\n");
    unlink($fake_codex);
    rmdir($tmp_root);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Limits monitoring smoke failed: {$e->getMessage()}\n");
    exit(1);
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
        throw new RuntimeException("Assertion failed for {$label}: missing {$needle}");
    }
}

function assertNotContains(string $needle, string $haystack, string $label): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException("Assertion failed for {$label}: unexpected {$needle}");
    }
}
