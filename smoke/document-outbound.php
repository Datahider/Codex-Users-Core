#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Document\DocumentSender;
use CodexRuntime\Document\FileExchangeClientInterface;
use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\HttpClientInterface;
use CodexRuntime\Router\RouterDeliveryClient;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_file = '/home/web/tmp/core-document-outbound-' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($tmp_file, 'document body');

try {
    $exchange = new class implements FileExchangeClientInterface {
        public function uploadFile(string $file_path, string $filename, string $mime): array
        {
            assertSame('report.txt', $filename, 'upload filename');
            assertSame('text/plain', $mime, 'upload mime');

            return [
                'file_id' => 'AbCd',
                'url' => 'https://files.ioannidis.ru/AbCd',
                'filename' => 'report.txt',
                'size' => 13,
            ];
        }
    };
    $http = new class implements HttpClientInterface {
        public ?string $body = null;

        public function request(string $method, string $url, array $headers, ?string $body = null): array
        {
            $this->body = $body;

            return ['status_code' => 200, 'body' => '{"accepted":true,"event_id":902}'];
        }
    };
    $sender = new DocumentSender(
        $exchange,
        new RouterDeliveryClient(new ApiClient('https://router.example', 'router-token', $http))
    );

    $result = $sender->send('runtime-42', $tmp_file, 'Report', 'report.txt');
    assertSame(true, $result['delivered'] ?? null, 'delivered');

    $payload = json_decode((string) $http->body, true);
    assertSame('document', $payload['kind'] ?? null, 'kind');
    assertSame('Report', $payload['text'] ?? null, 'caption');
    assertSame([[
        'file_id' => 'AbCd',
        'url' => 'https://files.ioannidis.ru/AbCd',
        'name' => 'report.txt',
        'mime' => 'text/plain',
        'size_bytes' => 13,
    ]], $payload['attachments'] ?? null, 'attachments');

    fwrite(STDOUT, "Document outbound smoke: OK\n");
} finally {
    if (is_file($tmp_file)) {
        unlink($tmp_file);
    }
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
