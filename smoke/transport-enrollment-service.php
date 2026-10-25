#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\HttpClientInterface;
use CodexRuntime\Router\RouterTransportEnrollmentService;

require_once __DIR__ . '/../src/bootstrap.php';

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
            'body' => '{"accepted":true,"enrollment_code":"7KQW-9MTR-X5NP-3YZA","expires_at":"2030-01-01T13:00:00+00:00"}',
        ];
    }
};

$service = new RouterTransportEnrollmentService(new ApiClient('https://router.example', 'core-token', $http));
$result = $service->createCode();

assertSame('POST', $http->method, 'method');
assertSame('https://router.example/api/v1/core/transport-enrollment-code', $http->url, 'URL');
assertSame('{}', $http->body, 'universal enrollment request body');
assertSame('7KQW-9MTR-X5NP-3YZA', $result['enrollment_code'] ?? null, 'enrollment code');

fwrite(STDOUT, "Transport enrollment service smoke: OK\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
