<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @return array{status_code: int, body: string}
     */
    public function request(string $method, string $url, array $headers, ?string $body = null): array;
}
