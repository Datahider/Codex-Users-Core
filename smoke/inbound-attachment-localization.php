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

$localizer = new InboundAttachmentLocalizer($downloader);
$localized = $localizer->localize([
    ['url' => 'https://files.ioannidis.ru/Doc1', 'type' => 'document', 'name' => 'report.pdf'],
    ['url' => 'https://files.ioannidis.ru/Image1', 'type' => 'image', 'name' => 'photo.jpg'],
]);

assertSame([
    ['https://files.ioannidis.ru/Doc1', 'report.pdf'],
    ['https://files.ioannidis.ru/Image1', 'photo.jpg'],
], $downloader->calls, 'download calls');
assertSame($first_file, $localized['attachments'][0]['local_path'] ?? null, 'first local path');
assertSame($second_file, $localized['attachments'][1]['local_path'] ?? null, 'second local path');
assertSame(true, is_file($first_file), 'first file remains available for Codex');
assertSame(true, is_file($second_file), 'second file remains available for Codex');

$localizer->cleanup($localized['file_paths']);
assertSame(false, is_file($first_file), 'first file removed after turn');
assertSame(false, is_file($second_file), 'second file removed after turn');

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
