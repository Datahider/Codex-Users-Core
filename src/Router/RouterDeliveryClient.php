<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use CodexRuntime\Contracts\DeliveryClientInterface;
use RuntimeException;

final class RouterDeliveryClient implements DeliveryClientInterface
{
    public function __construct(private ApiClient $api)
    {
    }

    public function sendMessage(
        int|string $chatId,
        string $text,
        ?int $replyToMessageId = null,
        ?string $parseMode = null,
        bool $disableNotification = false
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Cannot send an empty outbound message');
        }

        $response = $this->api->postJson('/api/v1/core/outbound', [
            'runtime_session_id' => (string) $chatId,
            'kind' => 'message',
            'text' => $text,
            'attachments' => [],
            'meta' => [
                'reply_to_message_id' => $replyToMessageId,
                'parse_mode' => $parseMode,
                'disable_notification' => $disableNotification,
            ],
        ]);

        return [
            'message_id' => $response['event_id'] ?? null,
            'accepted' => !empty($response['accepted']),
        ];
    }

    public function sendChatAction(int|string $chatId, string $action = 'typing'): void
    {
    }
}
