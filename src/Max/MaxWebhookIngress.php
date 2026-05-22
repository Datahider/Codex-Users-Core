<?php

declare(strict_types=1);

namespace CodexRuntime\Max;

use CodexRuntime\Config;
use RuntimeException;

final class MaxWebhookIngress
{
    public function __construct(
        private Config $config,
        private MaxUpdateIngress $ingress
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @return array{accepted: bool, event_id: ?string, reason: ?string}
     */
    public function ingest(string $rawBody, array $headers = []): array
    {
        $this->assertSecret($headers);

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid MAX webhook payload');
        }

        return $this->ingress->ingest($payload);
    }

    /**
     * @param array<string, string> $headers
     */
    private function assertSecret(array $headers): void
    {
        $expected = trim((string) $this->config->get('max', 'webhook_secret', ''));
        if ($expected === '') {
            return;
        }

        $headerName = strtolower(trim((string) $this->config->get('max', 'webhook_header', 'X-Max-Bot-Api-Secret')));
        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[strtolower($name)] = $value;
        }

        $actual = trim((string) ($normalizedHeaders[$headerName] ?? ''));
        if (!hash_equals($expected, $actual)) {
            throw new RuntimeException('Invalid MAX webhook secret');
        }
    }
}
