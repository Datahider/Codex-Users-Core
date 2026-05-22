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
    public function enqueueStop(
        int|string $channelId,
        ?string $sessionId = null,
        int|string|null $sourceMessageId = null,
        array $meta = []
    ): string {
        return $this->commands->enqueue([
            'type' => 'stop_turn',
            'channel_id' => $channelId,
            'session_id' => $sessionId,
            'source_message_id' => $sourceMessageId,
            'meta' => $meta,
            'priority' => 100,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function enqueueIngressCommand(
        int|string $channelId,
        string $text,
        ?string $sessionId = null,
        ?string $channelType = null,
        ?int $replyToMessageId = null,
        ?int $threadId = null,
        int|string|null $sourceMessageId = null,
        array $meta = []
    ): string {
        return $this->commands->enqueue([
            'type' => 'ingress_command',
            'channel_id' => $channelId,
            'text' => trim($text),
            'session_id' => $sessionId,
            'channel_type' => $channelType,
            'reply_to_message_id' => $replyToMessageId,
            'thread_id' => $threadId,
            'source_message_id' => $sourceMessageId,
            'meta' => $meta,
            'priority' => 90,
        ]);
    }
}
