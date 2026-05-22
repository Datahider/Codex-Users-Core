<?php

declare(strict_types=1);

namespace CodexRuntime\Contracts;

interface DeliveryClientInterface
{
    /**
     * @return array<string, mixed>
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?int $replyToMessageId = null,
        ?string $parseMode = null,
        bool $disableNotification = false
    ): array;

    public function sendChatAction(int|string $chatId, string $action = 'typing'): void;
}
