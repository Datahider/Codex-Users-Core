<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use CodexRuntime\Contracts\TransportClientInterface;

final class RouterTransportClient implements TransportClientInterface
{
    private readonly RouterDeliveryClient $delivery;

    public function __construct(ApiClient $api)
    {
        $this->delivery = new RouterDeliveryClient($api);
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
}
