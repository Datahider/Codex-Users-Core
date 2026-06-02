#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\ActiveTurnRegistry;
use CodexRuntime\CodexProcess;
use CodexRuntime\Config;
use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\ManagerWorker;
use CodexRuntime\NoopStatusMessageService;
use CodexRuntime\RuntimePaths;
use CodexRuntime\WorkerShutdownFlag;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $tmpRoot = sys_get_temp_dir() . '/codex-runtime-background-result-' . substr(bin2hex(random_bytes(4)), 0, 8);
    if (!mkdir($tmpRoot, 0775, true) && !is_dir($tmpRoot)) {
        throw new RuntimeException("Cannot create temp root {$tmpRoot}");
    }

    $codexBin = $tmpRoot . '/fake-codex.sh';
    file_put_contents($codexBin, <<<'SH'
#!/bin/sh

output_file=''
prev=''
for arg in "$@"; do
    if [ "$prev" = '-o' ]; then
        output_file="$arg"
        break
    fi
    prev="$arg"
done

if [ -z "$output_file" ]; then
    echo 'Missing output file' >&2
    exit 1
fi

cat >/dev/null

printf '%s\n' '{"type":"item.completed","item":{"type":"agent_message","text":"commentary chunk"}}'
sleep 1
printf '%s\n' '{"type":"item.started","item":{"type":"exec_command"}}'
sleep 2
printf '%s\n' 'final text' >"$output_file"
SH);
    chmod($codexBin, 0775);

    $config = new Config([
        'codex' => [
            'bin' => $codexBin,
            'cwd' => '/home/web',
        ],
        'storage' => [
            'root' => $tmpRoot . '/var',
        ],
    ]);

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
            $this->messages[] = [
                'chat_id' => (string) $chatId,
                'text' => $text,
                'disable_notification' => $disableNotification,
            ];

            return ['message_id' => count($this->messages)];
        }

        public function sendChatAction(int|string $chatId, string $action = 'typing'): void
        {
        }
    };

    $worker = new ManagerWorker(
        $config,
        new Logger($paths->logFile()),
        new EventRepository($config),
        new JsonFileStore($paths->managerStateFile()),
        new NoopStatusMessageService(),
        new WorkerShutdownFlag($config, 'manager_queue', 'shutdown_flag', $paths->workerShutdownFlagFile('manager_worker')),
        $transport,
        new CodexProcess($config, new Logger($paths->logFile()), new ActiveTurnRegistry($paths->activeTurnFile()))
    );

    $method = new ReflectionMethod(ManagerWorker::class, 'processBackgroundResult');
    $method->setAccessible(true);
    $result = $method->invoke($worker, [
        'type' => 'background_result',
        'session_id' => 'runtime-42',
        'codex_session_id' => 'codex-42',
        'job_id' => 'job-42',
        'ok' => true,
        'timed_out' => false,
        'exit_code' => 0,
        'command' => 'echo ok',
        'cwd' => '/home/web',
        'result_path' => '/tmp/background-result.txt',
    ]);

    if (!is_array($result)) {
        throw new RuntimeException('ManagerWorker did not return array result');
    }

    assertSame(2, count($transport->messages), 'outbound message count');
    assertSame('commentary chunk', $transport->messages[0]['text'] ?? null, 'commentary text');
    assertSame(true, $transport->messages[0]['disable_notification'] ?? null, 'commentary disable_notification');
    assertSame('final text', $transport->messages[1]['text'] ?? null, 'final text');
    assertSame(false, $transport->messages[1]['disable_notification'] ?? null, 'final disable_notification');
    assertSame('final text', $result['stdout'] ?? null, 'result stdout');

    fwrite(STDOUT, "Background result delivery smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Background result delivery smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        $expectedText = var_export($expected, true);
        $actualText = var_export($actual, true);
        throw new RuntimeException("Assertion failed for {$label}: expected {$expectedText}, got {$actualText}");
    }
}
