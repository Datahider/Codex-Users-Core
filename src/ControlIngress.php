<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\ControlQueue\CommandRepository;

final class ControlIngress
{
    public function __construct(private CommandRepository $commands)
    {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function enqueueTransportCommand(
        int|string $channelId,
        string $text,
        ?string $sessionId = null,
        ?string $channelType = null,
        ?int $replyToMessageId = null,
        ?int $threadId = null,
        int|string|null $transportMessageId = null,
        array $meta = []
    ): string {
        return $this->commands->enqueue([
            'type' => 'transport_command',
            'channel_id' => $channelId,
            'text' => trim($text),
            'session_id' => $sessionId,
            'channel_type' => $channelType,
            'reply_to_message_id' => $replyToMessageId,
            'thread_id' => $threadId,
            'transport_message_id' => $transportMessageId,
            'meta' => $meta,
            'priority' => 90,
        ]);
    }
}
