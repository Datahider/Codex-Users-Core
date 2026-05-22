#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\HttpClientInterface;
use CodexRuntime\Router\TransportIngressGateway;
use CodexRuntime\TransportInboundMessage;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $http = new class implements HttpClientInterface {
        public string $method = '';
        public string $url = '';
        /** @var array<string, string> */
        public array $headers = [];
        public ?string $body = null;

        public function request(string $method, string $url, array $headers, ?string $body = null): array
        {
            $this->method = $method;
            $this->url = $url;
            $this->headers = $headers;
            $this->body = $body;

            return [
                'status_code' => 200,
                'body' => '{"accepted":true,"event_id":101}',
            ];
        }
    };

    $gateway = new TransportIngressGateway(new ApiClient('https://router.example', 'test-token', $http));
    $result = $gateway->submitMessage(new TransportInboundMessage(
        channelId: 42,
        text: 'ping from smoke',
        sessionId: 'runtime-42',
        meta: ['source' => 'smoke']
    ));

    assertSame(true, $result['accepted'], 'accepted result');
    assertSame(101, $result['event_id'], 'event id');
    assertSame(null, $result['action_text'], 'action text');
    assertSame('POST', $http->method, 'HTTP method');
    assertSame('https://router.example/api/v1/transport/ingress', $http->url, 'HTTP URL');
    assertSame('Bearer test-token', $http->headers['Authorization'] ?? null, 'auth header');

    $payload = json_decode((string) $http->body, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Router request payload is not valid JSON');
    }

    assertSame('runtime-42', $payload['runtime_session_id'] ?? null, 'runtime session id');
    assertSame('message', $payload['kind'] ?? null, 'kind');
    assertSame('ping from smoke', $payload['text'] ?? null, 'text');
    assertSame([], $payload['attachments'] ?? null, 'attachments');
    assertSame(['source' => 'smoke'], $payload['meta'] ?? null, 'meta');

    fwrite(STDOUT, "Router transport ingress smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Router transport ingress smoke failed: {$e->getMessage()}\n");
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
