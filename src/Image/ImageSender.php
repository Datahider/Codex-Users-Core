<?php

declare(strict_types=1);

namespace CodexRuntime\Image;

use CodexRuntime\Document\FileExchangeClientInterface;
use CodexRuntime\Mcp\ImageToolInterface;
use CodexRuntime\Router\RouterDeliveryClient;
use RuntimeException;

final class ImageSender implements ImageToolInterface
{
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private FileExchangeClientInterface $file_exchange,
        private RouterDeliveryClient $delivery,
        private ?string $runtime_session_id = null
    ) {
    }

    public function sendImage(string $path, string $caption = ''): array
    {
        $runtime_session_id = trim((string) $this->runtime_session_id);
        if ($runtime_session_id === '') {
            throw new RuntimeException('RUNTIME_SID is required');
        }

        return $this->send($runtime_session_id, $path, $caption);
    }

    public function send(string $runtime_session_id, string $path, string $caption = '', ?string $filename = null): array
    {
        $real_path = realpath($path);
        if ($real_path === false || !is_file($real_path) || !is_readable($real_path)) {
            throw new RuntimeException('Image path must reference a readable regular file');
        }

        $mime = mime_content_type($real_path);
        if (!is_string($mime) || !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('Image must be JPEG, PNG or WEBP');
        }

        $size = filesize($real_path);
        if ($size === false || $size > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Image must not exceed 10 MB');
        }

        $filename = trim((string) ($filename ?? basename($real_path)));
        if ($filename === '') {
            throw new RuntimeException('Image filename cannot be empty');
        }

        $uploaded = $this->file_exchange->uploadFile($real_path, $filename, $mime);
        $file_id = trim((string) ($uploaded['file_id'] ?? ''));
        $url = trim((string) ($uploaded['url'] ?? ''));
        if ($file_id === '' || $url === '') {
            throw new RuntimeException('File exchange response is missing file_id or url');
        }

        $result = $this->delivery->sendImage($runtime_session_id, trim($caption), [[
            'file_id' => $file_id,
            'url' => $url,
            'name' => $filename,
            'mime' => $mime,
            'size_bytes' => $size,
        ]]);

        return [
            'delivered' => !empty($result['accepted']),
            'event_id' => $result['message_id'] ?? null,
            'filename' => $filename,
        ];
    }
}
