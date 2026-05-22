#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\CoreEventSource;
use CodexRuntime\Router\HttpClientInterface;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $http = new class implements HttpClientInterface {
        public string $url = '';

        public function request(string $method, string $url, array $headers, ?string $body = null): array
        {
            $this->url = $url;

            return [
                'status_code' => 200,
                'body' => '{"events":[{"event_id":501,"transport_instance_id":"transport-alpha","runtime_session_id":"runtime-42","kind":"message","text":"router ping","attachments":[],"meta":{"source":"smoke"}}]}',
            ];
        }
    };

    $source = new CoreEventSource(new ApiClient('https://router.example/', 'test-token', $http));
    $event = $source->pollNextEvent(500, 0, 1);
    if (!is_array($event)) {
        throw new RuntimeException('Router core events smoke expected one event');
    }

    assertSame('https://router.example/api/v1/core/events?after_id=500&wait=0&limit=1', $http->url, 'poll URL');
    assertSame('router:501', $event['id'] ?? null, 'event id');
    assertSame(501, $event['router_event_id'] ?? null, 'router event id');
    assertSame('user_message', $event['type'] ?? null, 'mapped type');
    assertSame('runtime-42', $event['session_id'] ?? null, 'runtime session id');
    assertSame('router ping', $event['text'] ?? null, 'text');
    assertSame('transport-alpha', $event['meta']['transport_instance_id'] ?? null, 'transport instance');
    assertSame('smoke', $event['meta']['router_meta']['source'] ?? null, 'router meta');

    fwrite(STDOUT, "Router core events smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Router core events smoke failed: {$e->getMessage()}\n");
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
