#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Attachment\AttachmentDownloaderInterface;
use CodexRuntime\Attachment\InboundAttachmentLocalizer;

require_once __DIR__ . '/../src/bootstrap.php';

$first_file = tempnam(sys_get_temp_dir(), 'localized-attachment-');
$second_file = tempnam(sys_get_temp_dir(), 'localized-attachment-');
if ($first_file === false || $second_file === false) {
    throw new RuntimeException('Cannot create attachment fixtures');
}
file_put_contents($first_file, 'first');
file_put_contents($second_file, 'second');

$downloader = new class([$first_file, $second_file]) implements AttachmentDownloaderInterface {
    public array $calls = [];

    public function __construct(private array $files)
    {
    }

    public function download(string $url, ?string $filename = null): string
    {
        $this->calls[] = [$url, $filename];
        $file = array_shift($this->files);
        if (!is_string($file)) {
            throw new RuntimeException('Unexpected attachment download');
        }

        return $file;
    }
};

$storage_root = sys_get_temp_dir() . '/attachment-storage-' . bin2hex(random_bytes(4));
$localizer = new InboundAttachmentLocalizer($downloader, $storage_root);
$localized = $localizer->localize('727f4a54-ab96-4494-8e42-2760555415d7', [
    ['url' => 'https://files.ioannidis.ru/Doc1', 'type' => 'document', 'name' => 'report.pdf'],
    ['url' => 'https://files.ioannidis.ru/Image1', 'type' => 'image', 'name' => 'photo.jpg'],
]);

assertSame([
    ['https://files.ioannidis.ru/Doc1', 'report.pdf'],
    ['https://files.ioannidis.ru/Image1', 'photo.jpg'],
], $downloader->calls, 'download calls');
$session_dir = $storage_root . '/727f4a54-ab96-4494-8e42-2760555415d7';
$first_stored_file = $session_dir . '/Doc1-report.pdf';
$second_stored_file = $session_dir . '/Image1-photo.jpg';
assertSame($first_stored_file, $localized[0]['local_path'] ?? null, 'first local path');
assertSame($second_stored_file, $localized[1]['local_path'] ?? null, 'second local path');
assertSame(true, is_file($first_stored_file), 'first file remains after turn');
assertSame(true, is_file($second_stored_file), 'second file remains after turn');
assertSame(false, is_file($first_file), 'first temporary download moved into storage');
assertSame(false, is_file($second_file), 'second temporary download moved into storage');

assertThrows(
    fn (): array => $localizer->localize('../invalid', []),
    'Invalid runtime session ID'
);

deleteTree($storage_root);

fwrite(STDOUT, "Inbound attachment localization smoke: OK\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Assertion failed for %s: expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        assertSame($message, $error->getMessage(), 'exception message');
        return;
    }

    throw new RuntimeException('Expected exception was not thrown');
}

function deleteTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}
