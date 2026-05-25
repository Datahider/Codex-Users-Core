<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class TransportInboundMessage
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly int|string $channelId,
        public readonly string $text,
        public readonly ?string $sessionId = null,
        public readonly ?string $channelType = null,
        public readonly ?int $replyToMessageId = null,
        public readonly ?int $threadId = null,
        public readonly int|string|null $transportMessageId = null,
        public readonly array $meta = []
    ) {
        if (trim($this->text) === '') {
            throw new RuntimeException('Inbound message text cannot be empty');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toManagerEventPayload(): array
    {
        $payload = [
            'type' => 'user_message',
            'text' => trim($this->text),
            'meta' => $this->meta,
        ];

        if ($this->sessionId !== null && trim($this->sessionId) !== '') {
            $payload['session_id'] = trim($this->sessionId);
        }

        return $payload;
    }
}
