<?php

declare(strict_types=1);

namespace CodexRuntime\Audio;

interface TranscriptionHttpClientInterface
{
    /**
     * @return array<string, mixed>
     */
    public function transcribe(string $url, string $api_key, string $file_path, string $model): array;
}
