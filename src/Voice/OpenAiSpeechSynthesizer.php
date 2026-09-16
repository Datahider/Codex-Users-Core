<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use RuntimeException;

final class OpenAiSpeechSynthesizer implements SpeechSynthesizerInterface
{
    private const ENDPOINT = 'https://api.openai.com/v1/audio/speech';

    public function __construct(
        private string $api_key,
        private string $model,
        private string $tmp_dir,
        private SpeechHttpClientInterface $http
    ) {
    }

    public function synthesize(string $text, string $voice): string
    {
        $api_key = trim($this->api_key);
        $model = trim($this->model);
        $text = trim($text);
        $voice = strtolower(trim($voice));
        if ($api_key === '') {
            throw new RuntimeException('Speech API key is empty');
        }
        if ($model === '') {
            throw new RuntimeException('Speech model is empty');
        }
        if ($text === '' || $voice === '') {
            throw new RuntimeException('Speech text and voice are required');
        }
        if (!is_dir($this->tmp_dir) && !mkdir($this->tmp_dir, 0775, true) && !is_dir($this->tmp_dir)) {
            throw new RuntimeException('Cannot create speech temporary directory');
        }

        $audio = $this->http->synthesize(self::ENDPOINT, $api_key, [
            'model' => $model,
            'voice' => $voice,
            'input' => $text,
            'response_format' => 'opus',
        ]);
        $path = rtrim($this->tmp_dir, '/') . '/voice-' . bin2hex(random_bytes(8)) . '.ogg';
        if (file_put_contents($path, $audio, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write synthesized voice file');
        }

        return $path;
    }
}
