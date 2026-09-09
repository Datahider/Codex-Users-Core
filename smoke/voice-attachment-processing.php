#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\Attachment\AttachmentDownloaderInterface;
use CodexRuntime\Attachment\VoiceAttachmentProcessor;
use CodexRuntime\AttachmentPromptFormatter;
use CodexRuntime\Audio\AudioTranscriberInterface;

$downloaded_file = tempnam(sys_get_temp_dir(), 'core-voice-smoke-');
if ($downloaded_file === false) {
    throw new RuntimeException('Cannot create voice fixture');
}
file_put_contents($downloaded_file, 'voice body');

$downloader = new class($downloaded_file) implements AttachmentDownloaderInterface {
    public array $urls = [];

    public function __construct(private string $file_path)
    {
    }

    public function download(string $url, ?string $filename = null): string
    {
        $this->urls[] = [$url, $filename];

        return $this->file_path;
    }
};

$transcriber = new class implements AudioTranscriberInterface {
    public array $file_paths = [];

    public function transcribe(string $file_path): string
    {
        if (!is_file($file_path)) {
            throw new RuntimeException('Voice file was deleted before transcription');
        }

        $this->file_paths[] = $file_path;

        return 'Распознанная речь';
    }
};

$processor = new VoiceAttachmentProcessor($downloader, $transcriber);
$result = $processor->process('Подпись пользователя', [
    [
        'type' => 'voice',
        'url' => 'https://files.ioannidis.ru/Voice1',
        'name' => 'voice.ogg',
    ],
    [
        'type' => 'audio',
        'url' => 'https://files.ioannidis.ru/Audio1',
        'name' => 'song.mp3',
    ],
]);

assertSame([['https://files.ioannidis.ru/Voice1', 'voice.ogg']], $downloader->urls, 'downloaded voice');
assertSame([$downloaded_file], $transcriber->file_paths, 'transcribed files');
assertSame(false, is_file($downloaded_file), 'temporary voice file removed');
assertSame('Распознанная речь' . "\n\n" . 'Подпись пользователя', $result['text'] ?? null, 'voice text with caption');
assertSame('Распознанная речь', $result['transcript'] ?? null, 'voice transcript');
assertSame([
    [
        'type' => 'audio',
        'url' => 'https://files.ioannidis.ru/Audio1',
        'name' => 'song.mp3',
    ],
], $result['attachments'] ?? null, 'non-voice attachments');
assertSame(
    "Вот файл(ы):\n- url: https://files.ioannidis.ru/Audio1; type: audio; name: song.mp3\n\nРаспознанная речь\n\nПодпись пользователя",
    AttachmentPromptFormatter::prependAttachments($result['text'], $result['attachments']),
    'final attachment prompt'
);

$failed_file = tempnam(sys_get_temp_dir(), 'core-voice-failed-smoke-');
if ($failed_file === false) {
    throw new RuntimeException('Cannot create failed voice fixture');
}
file_put_contents($failed_file, 'voice body');
$failed_downloader = new class($failed_file) implements AttachmentDownloaderInterface {
    public function __construct(private string $file_path)
    {
    }

    public function download(string $url, ?string $filename = null): string
    {
        return $this->file_path;
    }
};
$failed_transcriber = new class implements AudioTranscriberInterface {
    public function transcribe(string $file_path): string
    {
        throw new RuntimeException('Transcription failed');
    }
};
assertThrows(
    fn (): array => (new VoiceAttachmentProcessor($failed_downloader, $failed_transcriber))->process('', [[
        'type' => 'voice',
        'url' => 'https://files.ioannidis.ru/Voice2',
    ]]),
    'Transcription failed'
);
assertSame(false, is_file($failed_file), 'failed temporary voice file removed');

$audio_file = tempnam(sys_get_temp_dir(), 'core-audio-smoke-');
if ($audio_file === false) {
    throw new RuntimeException('Cannot create audio fixture');
}
file_put_contents($audio_file, 'audio body');

$audio_downloader = new class($audio_file) implements AttachmentDownloaderInterface {
    public int $calls = 0;

    public function __construct(private string $file_path)
    {
    }

    public function download(string $url, ?string $filename = null): string
    {
        $this->calls++;

        return $this->file_path;
    }
};
$audio_transcriber = new class implements AudioTranscriberInterface {
    public int $calls = 0;

    public function transcribe(string $file_path): string
    {
        $this->calls++;

        return 'unexpected';
    }
};

$audio_result = (new VoiceAttachmentProcessor($audio_downloader, $audio_transcriber))->process('', [[
    'type' => 'audio',
    'url' => 'https://files.ioannidis.ru/Audio1',
]]);

assertSame(0, $audio_downloader->calls, 'audio download calls');
assertSame(0, $audio_transcriber->calls, 'audio transcription calls');
assertSame('', $audio_result['text'] ?? null, 'audio text');
assertSame('', $audio_result['transcript'] ?? null, 'audio transcript');
assertSame('audio', $audio_result['attachments'][0]['type'] ?? null, 'audio attachment preserved');
unlink($audio_file);

fwrite(STDOUT, "Voice attachment processing smoke: OK\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException("{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $expected_message): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), $expected_message)) {
            return;
        }

        throw new RuntimeException("Expected error containing '{$expected_message}', got '{$e->getMessage()}'");
    }

    throw new RuntimeException("Expected error containing '{$expected_message}'");
}
