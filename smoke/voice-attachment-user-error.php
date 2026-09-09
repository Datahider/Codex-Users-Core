#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\ActiveTurnRegistry;
use CodexRuntime\Attachment\AttachmentDownloaderInterface;
use CodexRuntime\Attachment\VoiceAttachmentProcessor;
use CodexRuntime\Audio\AudioTranscriberInterface;
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

$tmp_root = sys_get_temp_dir() . '/core-voice-user-error-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    $config = new Config([
        'codex' => ['bin' => 'codex', 'cwd' => '/home/web'],
        'storage' => ['root' => $tmp_root],
    ]);
    $paths = new RuntimePaths($config);
    $transport = new class implements TransportClientInterface {
        public array $messages = [];

        public function sendMessage(int|string $chatId, string $text, ?int $replyToMessageId = null, ?string $parseMode = null, bool $disableNotification = false): array
        {
            $this->messages[] = ['chat_id' => $chatId, 'text' => $text];

            return ['message_id' => count($this->messages)];
        }

        public function sendChatAction(int|string $chatId, string $action = 'typing'): void
        {
        }

        public function sendTranscript(int|string $chatId, string $text): array
        {
            throw new RuntimeException('Unexpected transcript');
        }
    };
    $voice_processor = new VoiceAttachmentProcessor(
        new class implements AttachmentDownloaderInterface {
            public function download(string $url, ?string $filename = null): string
            {
                throw new RuntimeException('secret upstream error');
            }
        },
        new class implements AudioTranscriberInterface {
            public function transcribe(string $file_path): string
            {
                throw new RuntimeException('Unexpected transcription call');
            }
        }
    );
    $worker = new ManagerWorker(
        $config,
        new Logger($paths->logFile()),
        new EventRepository($config),
        new JsonFileStore($paths->managerStateFile()),
        new NoopStatusMessageService(),
        new WorkerShutdownFlag($config, 'manager_queue', 'shutdown_flag', $paths->workerShutdownFlagFile('manager_worker')),
        $transport,
        new CodexProcess($config, new Logger($paths->logFile()), new ActiveTurnRegistry($paths->activeTurnFile())),
        $voice_processor
    );

    $method = new ReflectionMethod(ManagerWorker::class, 'processUserMessage');
    try {
        $method->invoke($worker, [
            'type' => 'user_message',
            'session_id' => 'runtime-voice',
            'text' => '',
            'meta' => ['attachments' => [[
                'type' => 'voice',
                'url' => 'https://files.ioannidis.ru/Voice1',
                'name' => 'voice.ogg',
            ]]],
        ]);
        throw new RuntimeException('Voice processing error was not propagated');
    } catch (ReflectionException $error) {
        throw $error;
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), 'secret upstream error')) {
            throw $error;
        }
    }

    assertSame(1, count($transport->messages), 'user error message count');
    assertSame('runtime-voice', $transport->messages[0]['chat_id'] ?? null, 'user error runtime session');
    assertSame(
        'Не удалось расшифровать голосовое сообщение. Проверьте transcription.api_key и повторите отправку.',
        $transport->messages[0]['text'] ?? null,
        'safe user error text'
    );

    fwrite(STDOUT, "Voice attachment user error smoke: OK\n");
} finally {
    removeTree($tmp_root);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $child = $path . '/' . $name;
        is_dir($child) ? removeTree($child) : unlink($child);
    }
    rmdir($path);
}
