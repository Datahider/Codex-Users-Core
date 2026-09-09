<?php

declare(strict_types=1);

namespace CodexRuntime\Audio;

use RuntimeException;

final class GptAudioTranscriber implements AudioTranscriberInterface
{
    private const ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';

    public function __construct(
        private string $api_key,
        private string $model,
        private TranscriptionHttpClientInterface $http
    ) {
    }

    public function transcribe(string $file_path): string
    {
        $api_key = trim($this->api_key);
        if ($api_key === '') {
            throw new RuntimeException('Transcription API key is empty');
        }

        $model = trim($this->model);
        if ($model === '') {
            throw new RuntimeException('Transcription model is empty');
        }

        if (!is_file($file_path) || !is_readable($file_path)) {
            throw new RuntimeException("Audio file is not readable: {$file_path}");
        }

        $response = $this->http->transcribe(self::ENDPOINT, $api_key, $file_path, $model);
        $text = $response['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('OpenAI returned an empty transcription');
        }

        return trim($text);
    }
}
