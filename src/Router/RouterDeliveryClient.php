<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use CodexRuntime\Logger;
use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\Contracts\DeliveryClientInterface;
use RuntimeException;

final class RouterDeliveryClient implements DeliveryClientInterface, TransportClientInterface
{
    public function __construct(
        private ApiClient $api,
        private ?Logger $logger = null,
        private int $retryUnavailableAfterSeconds = 15
    ) {
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

        return $this->retryUntilDelivered(
            (string) $chatId,
            $disableNotification ? 'commentary' : 'final',
            fn (): array => $this->sendOutbound(
                (string) $chatId,
                $disableNotification ? 'commentary' : 'final',
                $text,
                [
                    'reply_to_message_id' => $replyToMessageId,
                    'parse_mode' => $parseMode,
                    'disable_notification' => $disableNotification,
                ]
            )
        );
    }

    public function sendChatAction(int|string $chatId, string $action = 'typing'): void
    {
    }

    public function sendHeartbeat(int|string $chatId): array
    {
        return $this->retryUntilDelivered(
            (string) $chatId,
            'heartbeat',
            fn (): array => $this->sendOutbound((string) $chatId, 'heartbeat', '', [])
        );
    }

    public function sendStatus(int|string $chatId, string $text, string $state, ?string $taskId = null): array
    {
        $meta = [
            'state' => trim($state),
        ];
        if ($taskId !== null && trim($taskId) !== '') {
            $meta['job_id'] = trim($taskId);
        }

        return $this->retryUntilDelivered(
            (string) $chatId,
            'status',
            fn (): array => $this->sendOutbound((string) $chatId, 'status', $text, $meta)
        );
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

    /**
     * @param callable(): array<string, mixed> $sender
     * @return array<string, mixed>
     */
    private function retryUntilDelivered(string $runtimeSessionId, string $kind, callable $sender): array
    {
        while (true) {
            try {
                return $sender();
            } catch (RouterUnavailableException $e) {
                $this->logger?->error('Router delivery unavailable, retrying outbound send', [
                    'runtime_session_id' => $runtimeSessionId,
                    'kind' => $kind,
                    'retry_after_seconds' => $this->retryDelaySeconds(),
                    'error' => $e->getMessage(),
                ]);

                $delaySeconds = $this->retryDelaySeconds();
                if ($delaySeconds > 0) {
                    sleep($delaySeconds);
                }
            }
        }
    }

    private function retryDelaySeconds(): int
    {
        return max(0, $this->retryUnavailableAfterSeconds);
    }
}
