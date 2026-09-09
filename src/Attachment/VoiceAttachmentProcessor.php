<?php

declare(strict_types=1);

namespace CodexRuntime\Attachment;

use CodexRuntime\Audio\AudioTranscriberInterface;
use RuntimeException;

final class VoiceAttachmentProcessor
{
    public function __construct(
        private AttachmentDownloaderInterface $downloader,
        private AudioTranscriberInterface $transcriber
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $attachments
     * @return array{text:string,attachments:array<int, array<string, mixed>>}
     */
    public function process(string $text, array $attachments): array
    {
        $transcriptions = [];
        $remaining_attachments = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || ($attachment['type'] ?? null) !== 'voice') {
                $remaining_attachments[] = $attachment;
                continue;
            }

            $url = $attachment['url'] ?? null;
            if (!is_string($url) || trim($url) === '') {
                throw new RuntimeException('Voice attachment URL is required');
            }

            $name = isset($attachment['name']) && is_string($attachment['name'])
                ? trim($attachment['name'])
                : null;
            $file_path = $this->downloader->download($url, $name !== '' ? $name : null);
            try {
                $transcriptions[] = $this->transcriber->transcribe($file_path);
            } finally {
                if (is_file($file_path)) {
                    unlink($file_path);
                }
            }
        }

        $text_parts = $transcriptions;
        $original_text = trim($text);
        if ($original_text !== '') {
            $text_parts[] = $original_text;
        }

        return [
            'text' => implode("\n\n", $text_parts),
            'attachments' => $remaining_attachments,
        ];
    }
}
