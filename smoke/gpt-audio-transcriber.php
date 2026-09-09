<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\Audio\AudioTranscriberInterface;
use CodexRuntime\Audio\GptAudioTranscriber;
use CodexRuntime\Audio\TranscriptionHttpClientInterface;
$tmp_file = tempnam(sys_get_temp_dir(), 'core-audio-');
if ($tmp_file === false) {
    throw new RuntimeException('Cannot create audio fixture');
}
file_put_contents($tmp_file, 'audio');

try {
    $http = new class implements TranscriptionHttpClientInterface {
        public string $url = '';
        public string $api_key = '';
        public string $file_path = '';
        public string $model = '';

        public function transcribe(string $url, string $api_key, string $file_path, string $model): array
        {
            $this->url = $url;
            $this->api_key = $api_key;
            $this->file_path = $file_path;
            $this->model = $model;

            return ['text' => '  Привет, мир.  '];
        }
    };

    $transcriber = new GptAudioTranscriber('secret', 'gpt-transcribe', $http);
    assertTrue($transcriber instanceof AudioTranscriberInterface, 'interface');
    assertSame('Привет, мир.', $transcriber->transcribe($tmp_file), 'transcript');
    assertSame('https://api.openai.com/v1/audio/transcriptions', $http->url, 'endpoint');
    assertSame('secret', $http->api_key, 'api key');
    assertSame($tmp_file, $http->file_path, 'file path');
    assertSame('gpt-transcribe', $http->model, 'model');

    assertThrows(
        fn (): string => (new GptAudioTranscriber('', 'gpt-transcribe', $http))->transcribe($tmp_file),
        'API key'
    );
    assertThrows(
        fn (): string => $transcriber->transcribe($tmp_file . '.missing'),
        'not readable'
    );

    $empty_http = new class implements TranscriptionHttpClientInterface {
        public function transcribe(string $url, string $api_key, string $file_path, string $model): array
        {
            return ['text' => '   '];
        }
    };
    assertThrows(
        fn (): string => (new GptAudioTranscriber('secret', 'gpt-transcribe', $empty_http))->transcribe($tmp_file),
        'empty transcription'
    );

    fwrite(STDOUT, "GPT audio transcriber smoke: OK\n");
} finally {
    unlink($tmp_file);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException("{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $actual, string $label): void
{
    if (!$actual) {
        throw new RuntimeException("{$label}: expected true");
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
