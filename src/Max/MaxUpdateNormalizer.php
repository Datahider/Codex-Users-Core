<?php

declare(strict_types=1);

namespace CodexRuntime\Max;

use CodexRuntime\TransportInboundMessage;

final class MaxUpdateNormalizer
{
    public function normalize(array $update): ?TransportInboundMessage
    {
        $type = strtolower(trim((string) ($update['update_type'] ?? $update['type'] ?? $update['event_type'] ?? '')));
        if ($type !== '' && !str_contains($type, 'message')) {
            return null;
        }

        $message = $update['message'] ?? $update['payload']['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $text = $this->extractText($message);
        if ($text === null) {
            return null;
        }

        $channelId = $this->extractChannelId($update, $message);
        if ($channelId === null || $channelId === '') {
            return null;
        }

        return new TransportInboundMessage(
            channelId: $channelId,
            text: $text,
            channelType: $this->extractChannelType($update, $message),
            replyToMessageId: $this->extractReplyToMessageId($message),
            transportMessageId: $this->extractMessageId($message),
            meta: array_filter([
                'update_type' => $type !== '' ? $type : null,
                'sender_id' => $message['sender']['user_id'] ?? $message['sender']['userId'] ?? null,
                'recipient_user_id' => $message['recipient']['user_id'] ?? $message['recipient']['userId'] ?? null,
                'chat_id' => $this->extractDialogChatId($update, $message),
            ], static fn (mixed $value): bool => $value !== null && $value !== '')
        );
    }

    private function extractText(array $message): ?string
    {
        $text = $message['body']['text']
            ?? $message['text']
            ?? $message['body']['markdown']
            ?? $message['body']['html']
            ?? null;

        if (!is_string($text) || trim($text) === '') {
            return null;
        }

        return trim($text);
    }

    private function extractChannelId(array $update, array $message): int|string|null
    {
        $chatId = $this->extractDialogChatId($update, $message);

        if (is_int($chatId) || is_string($chatId)) {
            return $chatId;
        }

        return null;
    }

    private function extractChannelType(array $update, array $message): ?string
    {
        $channelType = $update['chat_type']
            ?? $update['chatType']
            ?? $message['chat_type']
            ?? $message['chatType']
            ?? ($message['recipient']['chat_type'] ?? null)
            ?? ($message['recipient']['chatType'] ?? null);

        return is_string($channelType) && trim($channelType) !== '' ? trim($channelType) : null;
    }

    private function extractReplyToMessageId(array $message): ?int
    {
        $replyTo = $message['reply_to_message_id']
            ?? $message['replyToMessageId']
            ?? ($message['link']['message_id'] ?? null)
            ?? ($message['link']['messageId'] ?? null);

        return is_numeric($replyTo) ? (int) $replyTo : null;
    }

    private function extractMessageId(array $message): int|string|null
    {
        $messageId = $message['message_id'] ?? $message['messageId'] ?? null;

        return is_int($messageId) || is_string($messageId) ? $messageId : null;
    }

    private function extractDialogChatId(array $update, array $message): int|string|null
    {
        $chatId = $update['chat_id']
            ?? $update['chatId']
            ?? $message['chat_id']
            ?? $message['chatId']
            ?? ($message['recipient']['chat_id'] ?? null)
            ?? ($message['recipient']['chatId'] ?? null);

        return is_int($chatId) || is_string($chatId) ? $chatId : null;
    }
}
