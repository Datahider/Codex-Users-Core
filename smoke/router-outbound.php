#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\HttpClientInterface;
use CodexRuntime\Router\RouterDeliveryClient;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $http = new class implements HttpClientInterface {
        public string $method = '';
        public string $url = '';
        public ?string $body = null;

        public function request(string $method, string $url, array $headers, ?string $body = null): array
        {
            $this->method = $method;
            $this->url = $url;
            $this->body = $body;

            return [
                'status_code' => 200,
                'body' => '{"accepted":true,"event_id":501}',
            ];
        }
    };

    $delivery = new RouterDeliveryClient(new ApiClient('https://router.example', 'test-token', $http));
    $result = $delivery->sendMessage('runtime-42', 'done text');

    assertSame('POST', $http->method, 'HTTP method');
    assertSame('https://router.example/api/v1/core/outbound', $http->url, 'HTTP URL');
    assertSame(501, $result['message_id'] ?? null, 'message id');

    $payload = json_decode((string) $http->body, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Router outbound payload is not valid JSON');
    }

    assertSame('runtime-42', $payload['runtime_session_id'] ?? null, 'runtime session id');
    assertSame('final', $payload['kind'] ?? null, 'kind');
    assertSame('done text', $payload['text'] ?? null, 'text');
    assertSame([], $payload['attachments'] ?? null, 'attachments');

    $result = $delivery->sendTranscript('runtime-42', 'recognized speech');
    assertSame(501, $result['message_id'] ?? null, 'transcript message id');
    $payload = json_decode((string) $http->body, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Router transcript payload is not valid JSON');
    }
    assertSame('transcript', $payload['kind'] ?? null, 'transcript kind');
    assertSame('recognized speech', $payload['text'] ?? null, 'transcript text');

    $result = $delivery->sendWarning('runtime-42', 'limits low');
    assertSame(true, $result['accepted'] ?? null, 'warning accepted');
    $payload = json_decode((string) $http->body, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Warning outbound payload is not valid JSON');
    }
    assertSame('warning', $payload['kind'] ?? null, 'warning kind');
    assertSame('limits low', $payload['text'] ?? null, 'warning text');

    $result = $delivery->sendSystem('runtime-42', 'session reset');
    assertSame(true, $result['accepted'] ?? null, 'system accepted');
    $payload = json_decode((string) $http->body, true);
    if (!is_array($payload)) {
        throw new RuntimeException('System outbound payload is not valid JSON');
    }
    assertSame('system', $payload['kind'] ?? null, 'system kind');
    assertSame('session reset', $payload['text'] ?? null, 'system text');

    fwrite(STDOUT, "Router outbound smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Router outbound smoke failed: {$e->getMessage()}\n");
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
