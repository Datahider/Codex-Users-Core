<?php

declare(strict_types=1);

namespace CodexRuntime\Document;

interface FileExchangeClientInterface
{
    /** @return array<string, mixed> */
    public function uploadFile(string $file_path, string $filename, string $mime): array;
}
