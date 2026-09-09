<?php

declare(strict_types=1);

namespace CodexRuntime\Attachment;

interface AttachmentDownloaderInterface
{
    public function download(string $url, ?string $filename = null): string;
}
