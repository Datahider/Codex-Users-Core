<?php

declare(strict_types=1);

namespace CodexRuntime;

final class AttachmentPromptFormatter
{
    /**
     * @param array<int, array<string, mixed>> $attachments
     */
    public static function prependAttachments(string $text, array $attachments): string
    {
        $text = trim($text);
        $attachment_lines = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $line = self::buildAttachmentLine($attachment);
            if ($line !== null) {
                $attachment_lines[] = '- ' . $line;
            }
        }

        if ($attachment_lines === []) {
            return $text;
        }

        $attachments_block = "Вот файл(ы):\n" . implode("\n", $attachment_lines);
        if ($text === '') {
            return $attachments_block;
        }

        return $attachments_block . "\n\n" . $text;
    }

    /**
     * @param array<string, mixed> $attachment
     */
    private static function buildAttachmentLine(array $attachment): ?string
    {
        $parts = [];
        foreach (['url', 'type', 'name', 'mime', 'size_bytes', 'source', 'expires_at'] as $key) {
            if (!array_key_exists($key, $attachment)) {
                continue;
            }

            $value = $attachment[$key];
            if ($value === null) {
                continue;
            }

            $value_text = trim((string) $value);
            if ($value_text === '') {
                continue;
            }

            $parts[] = $key . ': ' . $value_text;
        }

        if ($parts === []) {
            return null;
        }

        return implode('; ', $parts);
    }
}
