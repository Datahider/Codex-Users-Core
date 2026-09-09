#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\Attachment\FilesIoannidisAttachmentDownloader;
use CodexRuntime\Attachment\FileDownloadHttpClientInterface;

$http = new class implements FileDownloadHttpClientInterface {
    public string $url = '';
    public array $headers = [];

    public function download(string $url, array $headers, string $destination): void
    {
        $this->url = $url;
        $this->headers = $headers;
        file_put_contents($destination, 'voice body');
    }
};

$downloader = new FilesIoannidisAttachmentDownloader($http);
$file_path = $downloader->download('https://files.ioannidis.ru/Voice1', 'voice.ogg');

try {
    assertSame('https://files.ioannidis.ru/Voice1?download=1', $http->url, 'download URL');
    assertSame(['Referer' => 'https://files.ioannidis.ru/'], $http->headers, 'download headers');
    assertSame('voice body', file_get_contents($file_path), 'downloaded body');
    assertSame('ogg', pathinfo($file_path, PATHINFO_EXTENSION), 'temporary extension');
    assertThrows(fn (): string => $downloader->download('https://example.com/Voice1'), 'Invalid files.ioannidis.ru attachment URL');
} finally {
    if (is_file($file_path)) {
        unlink($file_path);
    }
}

$failed_http = new class implements FileDownloadHttpClientInterface {
    public string $destination = '';

    public function download(string $url, array $headers, string $destination): void
    {
        $this->destination = $destination;
        file_put_contents($destination, 'partial body');
        throw new RuntimeException('HTTP failure');
    }
};
$failed_downloader = new FilesIoannidisAttachmentDownloader($failed_http);
assertThrows(fn (): string => $failed_downloader->download('https://files.ioannidis.ru/Voice2'), 'HTTP failure');
assertSame(false, is_file($failed_http->destination), 'failed download temporary file removed');

fwrite(STDOUT, "Files Ioannidis attachment downloader smoke: OK\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException("{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $expected_message): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), $expected_message)) {
            return;
        }

        throw new RuntimeException("Expected error containing '{$expected_message}', got '{$e->getMessage()}'");
    }

    throw new RuntimeException("Expected error containing '{$expected_message}'");
}
