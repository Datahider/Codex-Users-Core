<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\OutboundQueue\MessageRepository;
use RuntimeException;

final class QueueTransportClient implements TransportClientInterface
{
    public function __construct(private MessageRepository $messages)
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
            throw new RuntimeException('Cannot enqueue an empty outbound message');
        }

        $id = $this->messages->enqueue([
            'type' => 'message',
            'kind' => $disableNotification ? 'commentary' : 'final',
            'session_id' => (string) $chatId,
            'text' => $text,
        ]);

        return ['message_id' => $id];
    }

    public function sendChatAction(int|string $chatId, string $action = 'typing'): void
    {
        $this->messages->enqueue([
            'type' => 'chat_action',
            'kind' => 'chat_action',
            'session_id' => (string) $chatId,
            'action' => trim($action) !== '' ? trim($action) : 'typing',
        ]);
    }
}
