#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\HttpClientInterface;
use CodexRuntime\Router\RouterDeliveryClient;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $http = new class implements HttpClientInterface {
        public int $attempts = 0;
        public ?string $lastBody = null;

        public function request(string $method, string $url, array $headers, ?string $body = null): array
        {
            $this->attempts++;
            $this->lastBody = $body;

            if ($this->attempts < 3) {
                throw new RuntimeException('Router HTTP request failed: connection refused');
            }

            return [
                'status_code' => 200,
                'body' => '{"accepted":true,"event_id":777}',
            ];
        }
    };

    $delivery = new RouterDeliveryClient(
        new ApiClient('https://router.example', 'test-token', $http),
        null,
        0
    );
    $result = $delivery->sendMessage('runtime-42', 'done text');

    assertSame(3, $http->attempts, 'retry attempts');
    assertSame(777, $result['message_id'] ?? null, 'message id');

    $payload = json_decode((string) $http->lastBody, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Router outbound retry payload is not valid JSON');
    }

    assertSame('runtime-42', $payload['runtime_session_id'] ?? null, 'runtime session id');
    assertSame('final', $payload['kind'] ?? null, 'kind');
    assertSame('done text', $payload['text'] ?? null, 'text');

    fwrite(STDOUT, "Router outbound retry smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Router outbound retry smoke failed: {$e->getMessage()}\n");
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
