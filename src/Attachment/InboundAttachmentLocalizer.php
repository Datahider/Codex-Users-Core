<?php

declare(strict_types=1);

namespace CodexRuntime\Attachment;

use RuntimeException;
use Throwable;

final class InboundAttachmentLocalizer
{
    public function __construct(private AttachmentDownloaderInterface $downloader)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $attachments
     * @return array{attachments:array<int, array<string, mixed>>,file_paths:list<string>}
     */
    public function localize(array $attachments): array
    {
        $localized_attachments = [];
        $file_paths = [];

        try {
            foreach ($attachments as $attachment) {
                if (!is_array($attachment)) {
                    throw new RuntimeException('Inbound attachment must be an array');
                }

                $url = $attachment['url'] ?? null;
                if (!is_string($url) || trim($url) === '') {
                    throw new RuntimeException('Inbound attachment URL is required');
                }

                $name = isset($attachment['name']) && is_string($attachment['name'])
                    ? trim($attachment['name'])
                    : null;
                $file_path = $this->downloader->download($url, $name !== '' ? $name : null);
                $file_paths[] = $file_path;
                $localized_attachments[] = ['local_path' => $file_path] + $attachment;
            }
        } catch (Throwable $error) {
            $this->cleanup($file_paths);
            throw $error;
        }

        return [
            'attachments' => $localized_attachments,
            'file_paths' => $file_paths,
        ];
    }

    /** @param list<string> $file_paths */
    public function cleanup(array $file_paths): void
    {
        foreach ($file_paths as $file_path) {
            if (is_file($file_path) && !unlink($file_path)) {
                throw new RuntimeException("Cannot remove temporary attachment file: {$file_path}");
            }
        }
    }
}
