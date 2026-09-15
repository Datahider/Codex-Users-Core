<?php

declare(strict_types=1);

namespace CodexRuntime\Attachment;

use RuntimeException;

final class FilesIoannidisAttachmentDownloader implements AttachmentDownloaderInterface
{
    private const REFERER = 'https://files.ioannidis.ru/';

    public function __construct(private FileDownloadHttpClientInterface $http)
    {
    }

    public function download(string $url, ?string $filename = null): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'files.ioannidis.ru'
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !preg_match('#^/[A-Za-z0-9]+$#D', (string) ($parts['path'] ?? ''))
        ) {
            throw new RuntimeException('Invalid files.ioannidis.ru attachment URL');
        }

        $file_path = tempnam(sys_get_temp_dir(), 'core-attachment-');
        if ($file_path === false) {
            throw new RuntimeException('Cannot create temporary attachment file');
        }

        $extension = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));
        if ($extension !== '' && preg_match('/^[a-z0-9]+$/D', $extension) === 1) {
            $file_path_with_extension = $file_path . '.' . $extension;
            if (!rename($file_path, $file_path_with_extension)) {
                unlink($file_path);
                throw new RuntimeException('Cannot preserve temporary attachment file extension');
            }
            $file_path = $file_path_with_extension;
        }

        try {
            $this->http->download($url . '?download=1', ['Referer' => self::REFERER], $file_path);

            return $file_path;
        } catch (\Throwable $error) {
            if (is_file($file_path)) {
                unlink($file_path);
            }
            throw $error;
        }
    }
}
