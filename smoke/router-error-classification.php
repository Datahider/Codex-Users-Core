#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\HttpClientInterface;
use CodexRuntime\Router\RouterAuthException;
use CodexRuntime\Router\RouterUnavailableException;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $authHttp = new class implements HttpClientInterface {
        public function request(string $method, string $url, array $headers, ?string $body = null): array
        {
            return [
                'status_code' => 401,
                'body' => '{"message":"Unauthorized"}',
            ];
        }
    };

    $unavailableHttp = new class implements HttpClientInterface {
        public function request(string $method, string $url, array $headers, ?string $body = null): array
        {
            throw new RuntimeException('Router HTTP request failed: connection refused');
        }
    };

    assertThrows(fn (): array => (new ApiClient('https://router.example', 'bad-token', $authHttp))->getJson('/api/v1/core/events'), RouterAuthException::class, 'auth classification');
    assertThrows(fn (): array => (new ApiClient('https://router.example', 'token', $unavailableHttp))->getJson('/api/v1/core/events'), RouterUnavailableException::class, 'unavailable classification');

    fwrite(STDOUT, "Router error classification smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Router error classification smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertThrows(callable $fn, string $expectedClass, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $expectedClass) {
            return;
        }

        throw new RuntimeException("Assertion failed for {$label}: expected {$expectedClass}, got " . $e::class);
    }

    throw new RuntimeException("Assertion failed for {$label}: no exception thrown");
}
