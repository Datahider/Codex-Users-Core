<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use CodexRuntime\Logger;
use CodexRuntime\Contracts\TransportClientInterface;

final class RouterTransportClient implements TransportClientInterface
{
    private readonly RouterDeliveryClient $delivery;

    public function __construct(
        ApiClient $api,
        ?Logger $logger = null,
        int $retryUnavailableAfterSeconds = 15
    ) {
        $this->delivery = new RouterDeliveryClient($api, $logger, $retryUnavailableAfterSeconds);
    }

    public function sendMessage(
        int|string $chatId,
        string $text,
        ?int $replyToMessageId = null,
        ?string $parseMode = null,
        bool $disableNotification = false
    ): array {
        return $this->delivery->sendMessage($chatId, $text, $replyToMessageId, $parseMode, $disableNotification);
    }

    public function sendChatAction(int|string $chatId, string $action = 'typing'): void
    {
        $this->delivery->sendChatAction($chatId, $action);
    }

    public function sendStatus(int|string $chatId, string $text, string $state, ?string $taskId = null): array
    {
        return $this->delivery->sendStatus($chatId, $text, $state, $taskId);
    }

    public function sendHeartbeat(int|string $chatId): array
    {
        return $this->delivery->sendHeartbeat($chatId);
    }
}
