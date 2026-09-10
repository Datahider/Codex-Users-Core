<?php

declare(strict_types=1);

namespace CodexRuntime\Document;

use CodexRuntime\Mcp\DocumentToolInterface;
use CodexRuntime\Router\RouterDeliveryClient;
use RuntimeException;

final class DocumentSender implements DocumentToolInterface
{
    public function __construct(
        private FileExchangeClientInterface $file_exchange,
        private RouterDeliveryClient $delivery,
        private ?string $runtime_session_id = null
    ) {
    }

    public function sendDocument(string $path, string $caption = ''): array
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
            throw new RuntimeException('Document path must reference a readable regular file');
        }

        $filename = trim((string) ($filename ?? basename($real_path)));
        if ($filename === '') {
            throw new RuntimeException('Document filename cannot be empty');
        }

        $mime = mime_content_type($real_path);
        if (!is_string($mime) || trim($mime) === '') {
            throw new RuntimeException('Cannot determine document MIME type');
        }

        $size = filesize($real_path);
        if ($size === false) {
            throw new RuntimeException('Cannot determine document size');
        }

        $uploaded = $this->file_exchange->uploadFile($real_path, $filename, $mime);
        $file_id = trim((string) ($uploaded['file_id'] ?? ''));
        $url = trim((string) ($uploaded['url'] ?? ''));
        if ($file_id === '' || $url === '') {
            throw new RuntimeException('File exchange response is missing file_id or url');
        }

        $result = $this->delivery->sendDocument($runtime_session_id, trim($caption), [[
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
