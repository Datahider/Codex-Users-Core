<?php

declare(strict_types=1);

namespace CodexRuntime\Contracts;

interface TransportClientInterface
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

    /**
     * @return array<string, mixed>
     */
    public function sendTranscript(int|string $chatId, string $text): array;

    public function sendChatAction(int|string $chatId, string $action = 'typing'): void;
}
