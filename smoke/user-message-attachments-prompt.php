#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\AttachmentPromptFormatter;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $attachments = [
        [
            'url' => 'https://files.ioannidis.ru/GPVn',
            'type' => 'document',
            'name' => 'files-verify-kccq.txt',
            'size_bytes' => 37,
        ],
        [
            'url' => 'https://files.ioannidis.ru/AbCd',
        ],
    ];

    $actual = AttachmentPromptFormatter::prependAttachments('Проверка загрузки файла', $attachments);
    $expected = <<<TEXT
Вот файл(ы):
- url: https://files.ioannidis.ru/GPVn; type: document; name: files-verify-kccq.txt; size_bytes: 37
- url: https://files.ioannidis.ru/AbCd

Проверка загрузки файла
TEXT;

    assertSame($expected, $actual, 'formatted prompt');

    $plain = AttachmentPromptFormatter::prependAttachments('Просто текст', []);
    assertSame('Просто текст', $plain, 'plain prompt');

    $attachmentsOnly = AttachmentPromptFormatter::prependAttachments('', [
        ['url' => 'https://files.ioannidis.ru/Only1'],
    ]);
    assertSame("Вот файл(ы):\n- url: https://files.ioannidis.ru/Only1", $attachmentsOnly, 'attachments without text');

    fwrite(STDOUT, "User message attachments prompt smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "User message attachments prompt smoke failed: {$e->getMessage()}\n");
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
