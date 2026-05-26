<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\Contracts\DeliveryClientInterface;
use RuntimeException;

final class RouterDeliveryClient implements DeliveryClientInterface, TransportClientInterface
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

        return $this->sendOutbound(
            (string) $chatId,
            $disableNotification ? 'commentary' : 'final',
            $text,
            [
                'reply_to_message_id' => $replyToMessageId,
                'parse_mode' => $parseMode,
                'disable_notification' => $disableNotification,
            ]
        );
    }

    public function sendChatAction(int|string $chatId, string $action = 'typing'): void
    {
    }

    public function sendHeartbeat(int|string $chatId): array
    {
        return $this->sendOutbound((string) $chatId, 'heartbeat', '', []);
    }

    public function sendStatus(int|string $chatId, string $text, string $state, ?string $taskId = null): array
    {
        $meta = [
            'state' => trim($state),
        ];
        if ($taskId !== null && trim($taskId) !== '') {
            $meta['job_id'] = trim($taskId);
        }

        return $this->sendOutbound((string) $chatId, 'status', $text, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function sendOutbound(string $runtimeSessionId, string $kind, string $text, array $meta): array
    {
        $response = $this->api->postJson('/api/v1/core/outbound', [
            'runtime_session_id' => $runtimeSessionId,
            'kind' => $kind,
            'text' => $text,
            'attachments' => [],
            'meta' => $meta,
        ]);

        return [
            'message_id' => $response['event_id'] ?? null,
            'accepted' => !empty($response['accepted']),
        ];
    }
}
