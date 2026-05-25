<?php

declare(strict_types=1);

namespace CodexRuntime\Telegram;

use CodexRuntime\TransportInboundMessage;

final class TelegramUpdateNormalizer
{
    public function normalize(array $update): ?TransportInboundMessage
    {
        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $text = $message['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return null;
        }

        $chat = $message['chat'] ?? null;
        if (!is_array($chat)) {
            return null;
        }

        $channelId = $chat['id'] ?? null;
        if (!is_int($channelId) && !is_string($channelId)) {
            return null;
        }

        $messageId = $message['message_id'] ?? null;
        if (!is_int($messageId) && !is_string($messageId) && $messageId !== null) {
            $messageId = null;
        }

        return new TransportInboundMessage(
            channelId: $channelId,
            text: trim($text),
            channelType: $this->channelType($chat),
            replyToMessageId: $this->replyToMessageId($message),
            threadId: $this->threadId($message),
            transportMessageId: $messageId,
            meta: array_filter([
                'transport' => 'telegram',
                'update_type' => 'message',
                'sender_id' => $message['from']['id'] ?? null,
                'chat_id' => $channelId,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')
        );
    }

    private function channelType(array $chat): ?string
    {
        $type = $chat['type'] ?? null;

        return is_string($type) && trim($type) !== '' ? trim($type) : null;
    }

    private function replyToMessageId(array $message): ?int
    {
        $replyToMessageId = $message['reply_to_message']['message_id'] ?? null;

        return is_numeric($replyToMessageId) ? (int) $replyToMessageId : null;
    }

    private function threadId(array $message): ?int
    {
        $threadId = $message['message_thread_id'] ?? null;

        return is_numeric($threadId) ? (int) $threadId : null;
    }
}
