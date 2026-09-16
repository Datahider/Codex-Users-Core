<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

interface SpeechHttpClientInterface
{
    public function synthesize(string $url, string $api_key, array $payload): string;
}
