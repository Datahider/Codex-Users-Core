<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use CodexRuntime\Document\FileExchangeClientInterface;
use CodexRuntime\Router\RouterDeliveryClient;
use RuntimeException;

final class VoiceSender implements VoiceOutboundSenderInterface
{
    public function __construct(
        private FileExchangeClientInterface $file_exchange,
        private RouterDeliveryClient $delivery
    ) {
    }

    public function send(string $runtime_session_id, string $file_path): array
    {
        $real_path = realpath($file_path);
        if ($real_path === false || !is_file($real_path) || !is_readable($real_path)) {
            throw new RuntimeException('Voice path must reference a readable regular file');
        }
        $size = filesize($real_path);
        if ($size === false) {
            throw new RuntimeException('Cannot determine voice size');
        }
        $filename = basename($real_path);
        $uploaded = $this->file_exchange->uploadFile($real_path, $filename, 'audio/ogg');
        $file_id = trim((string) ($uploaded['file_id'] ?? ''));
        $url = trim((string) ($uploaded['url'] ?? ''));
        if ($file_id === '' || $url === '') {
            throw new RuntimeException('File exchange response is missing file_id or url');
        }
        $result = $this->delivery->sendVoice($runtime_session_id, [[
            'file_id' => $file_id,
            'url' => $url,
            'name' => $filename,
            'mime' => 'audio/ogg',
            'size_bytes' => $size,
        ]]);

        return [
            'delivered' => !empty($result['accepted']),
            'event_id' => $result['message_id'] ?? null,
            'filename' => $filename,
        ];
    }
}
