<?php

declare(strict_types=1);

namespace CodexRuntime\Attachment;

interface FileDownloadHttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function download(string $url, array $headers, string $destination): void;
}
