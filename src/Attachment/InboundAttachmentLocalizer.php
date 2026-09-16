<?php

declare(strict_types=1);

namespace CodexRuntime\Attachment;

use RuntimeException;

final class InboundAttachmentLocalizer
{
    public function __construct(
        private AttachmentDownloaderInterface $downloader,
        private string $storage_root
    )
    {
    }

    /**
     * @param array<int, array<string, mixed>> $attachments
     * @return array<int, array<string, mixed>>
     */
    public function localize(string $runtime_session_id, array $attachments): array
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $runtime_session_id) !== 1) {
            throw new RuntimeException('Invalid runtime session ID');
        }
        if ($attachments === []) {
            return [];
        }

        $session_dir = rtrim($this->storage_root, '/') . '/' . $runtime_session_id;
        if (!is_dir($session_dir) && !mkdir($session_dir, 0775, true) && !is_dir($session_dir)) {
            throw new RuntimeException("Cannot create attachment session directory: {$session_dir}");
        }

        $localized_attachments = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                throw new RuntimeException('Inbound attachment must be an array');
            }

            $url = $attachment['url'] ?? null;
            if (!is_string($url) || trim($url) === '') {
                throw new RuntimeException('Inbound attachment URL is required');
            }

            $file_id = basename((string) parse_url($url, PHP_URL_PATH));
            if (preg_match('/^[A-Za-z0-9]+$/D', $file_id) !== 1) {
                throw new RuntimeException('Invalid attachment file ID');
            }

            $name = isset($attachment['name']) && is_string($attachment['name'])
                ? trim($attachment['name'])
                : '';
            $safe_name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name));
            $stored_name = $file_id . ($safe_name !== null && $safe_name !== '' ? '-' . $safe_name : '');
            $destination = $session_dir . '/' . $stored_name;
            $temporary_file = $this->downloader->download($url, $name !== '' ? $name : null);
            $partial_file = $destination . '.part-' . bin2hex(random_bytes(6));
            if (!copy($temporary_file, $partial_file)) {
                if (is_file($temporary_file)) {
                    unlink($temporary_file);
                }
                throw new RuntimeException("Cannot store inbound attachment: {$destination}");
            }
            unlink($temporary_file);
            if (!rename($partial_file, $destination)) {
                unlink($partial_file);
                throw new RuntimeException("Cannot publish inbound attachment: {$destination}");
            }

            $localized_attachments[] = ['local_path' => $destination] + $attachment;
        }

        return $localized_attachments;
    }
}
