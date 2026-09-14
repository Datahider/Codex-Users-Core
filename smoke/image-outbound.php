#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Image\ImageSender;
use CodexRuntime\Document\FileExchangeClientInterface;
use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\HttpClientInterface;
use CodexRuntime\Router\RouterDeliveryClient;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_file = '/home/web/tmp/core-image-outbound-' . bin2hex(random_bytes(4)) . '.png';
file_put_contents($tmp_file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

try {
    $size = filesize($tmp_file);
    $exchange = new class implements FileExchangeClientInterface {
        public function uploadFile(string $file_path, string $filename, string $mime): array
        {
            assertSame('pixel.png', $filename, 'upload filename');
            assertSame('image/png', $mime, 'upload mime');

            return ['file_id' => 'Img1', 'url' => 'https://files.ioannidis.ru/Img1'];
        }
    };
    $http = new class implements HttpClientInterface {
        public ?string $body = null;

        public function request(string $method, string $url, array $headers, ?string $body = null): array
        {
            $this->body = $body;
            return ['status_code' => 200, 'body' => '{"accepted":true,"event_id":903}'];
        }
    };
    $sender = new ImageSender($exchange, new RouterDeliveryClient(new ApiClient('https://router.example', 'router-token', $http)));
    $result = $sender->send('runtime-42', $tmp_file, 'Pixel', 'pixel.png');
    assertSame(true, $result['delivered'] ?? null, 'delivered');

    $payload = json_decode((string) $http->body, true);
    assertSame('image', $payload['kind'] ?? null, 'kind');
    assertSame('Pixel', $payload['text'] ?? null, 'caption');
    assertSame([[
        'file_id' => 'Img1',
        'url' => 'https://files.ioannidis.ru/Img1',
        'name' => 'pixel.png',
        'mime' => 'image/png',
        'size_bytes' => $size,
    ]], $payload['attachments'] ?? null, 'attachments');

    fwrite(STDOUT, "Image outbound smoke: OK\n");
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
