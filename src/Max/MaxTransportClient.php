<?php

declare(strict_types=1);

namespace CodexRuntime\Max;

use CodexRuntime\Contracts\TransportClientInterface;
use MaxApi\BotApi;
use MaxApi\Exception\ApiException;
use MaxApi\Input\ChatAction;
use MaxApi\Input\NewMessageBody;
use MaxApi\Input\PinMessage;
use RuntimeException;

final class MaxTransportClient implements TransportClientInterface
{
    public function __construct(private BotApi $api)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessageToChat(
        int $chatId,
        string $text,
        ?int $replyToMessageId = null,
        ?string $parseMode = null,
        bool $disableNotification = false
    ): array {
        return $this->sendMessageInternal(
            text: $text,
            chatId: $chatId,
            userId: null,
            replyToMessageId: $replyToMessageId,
            parseMode: $parseMode,
            disableNotification: $disableNotification
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessageToUser(
        int $userId,
        string $text,
        ?int $replyToMessageId = null,
        ?string $parseMode = null,
        bool $disableNotification = false
    ): array {
        return $this->sendMessageInternal(
            text: $text,
            chatId: null,
            userId: $userId,
            replyToMessageId: $replyToMessageId,
            parseMode: $parseMode,
            disableNotification: $disableNotification
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sendChatActionToChat(int $chatId, string $action = 'typing'): array
    {
        $resolvedAction = match (strtolower(trim($action))) {
            '', 'typing', 'typing_on' => 'typing_on',
            default => 'typing_on',
        };

        $result = $this->api->chats->sendAction($chatId, ChatAction::make($resolvedAction));

        return method_exists($result, 'toArray') ? $result->toArray() : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function editMessage(
        string $messageId,
        string $text,
        ?string $parseMode = null
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Cannot edit a MAX message to empty text');
        }

        $payload = NewMessageBody::make($text);
        $format = $this->normalizeParseMode($parseMode);
        if ($format !== null) {
            $payload = $payload->format($format);
        }

        $result = $this->api->messages->editMessage($messageId, $payload);

        return method_exists($result, 'toArray') ? $result->toArray() : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function pinMessage(int $chatId, string $messageId, bool $notify = false): array
    {
        $result = $this->api->chats->pinMessage($chatId, PinMessage::make($messageId)->notify($notify));

        return method_exists($result, 'toArray') ? $result->toArray() : [];
    }

    public function sendMessage(
        int|string $chatId,
        string $text,
        ?int $replyToMessageId = null,
        ?string $parseMode = null,
        bool $disableNotification = false
    ): array {
        $target = $this->normalizeTarget($chatId);
        return $this->sendMessageInternal(
            text: $text,
            chatId: $target['type'] === 'chat' ? $target['id'] : null,
            userId: $target['type'] === 'user' ? $target['id'] : null,
            replyToMessageId: $replyToMessageId,
            parseMode: $parseMode,
            disableNotification: $disableNotification
        );
    }

    public function sendChatAction(int|string $chatId, string $action = 'typing'): void
    {
        $target = $this->normalizeTarget($chatId);
        if ($target['type'] !== 'chat') {
            return;
        }

        $this->sendChatActionToChat($target['id'], $action);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTextFileToChat(
        int $chatId,
        string $title,
        string $contents,
        ?int $replyToMessageId = null,
        bool $disableNotification = false,
        ?string $filename = null
    ): array {
        $contents = trim($contents);
        if ($contents === '') {
            throw new RuntimeException('Cannot send an empty MAX file payload');
        }

        $filename ??= 'codex-message-' . date('Ymd-His') . '.txt';
        $upload = $this->api->uploads->create('file');
        $uploadResult = $this->api->uploads->uploadMultipart(
            (string) $upload->url,
            $contents,
            $filename,
            'text/plain; charset=utf-8'
        );

        $token = $this->extractUploadToken($uploadResult, $upload->token);
        if ($token === null) {
            throw new RuntimeException('MAX upload response did not return a usable file token');
        }

        $payload = NewMessageBody::make($title)
            ->attachments([[
                'type' => 'file',
                'payload' => [
                    'token' => $token,
                ],
            ]])
            ->notify(!$disableNotification);

        if ($replyToMessageId !== null) {
            $payload = $payload->replyToMessageId((string) $replyToMessageId);
        }

        $message = $this->sendAttachmentMessageWithRetry($payload, $chatId);

        return $this->normalizeMessage($message);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMessage(mixed $message): array
    {
        if (is_array($message)) {
            return $message;
        }

        if (is_object($message)) {
            if (method_exists($message, 'id')) {
                $messageId = $message->id();
                if ($messageId !== null && $messageId !== '') {
                    return ['message_id' => (string) $messageId];
                }
            }

            $data = get_object_vars($message);
            if ($data !== []) {
                return $data;
            }

            if (property_exists($message, 'messageId')) {
                return ['message_id' => $message->messageId];
            }

            if (property_exists($message, 'message_id')) {
                return ['message_id' => $message->message_id];
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendMessageInternal(
        string $text,
        ?int $chatId,
        ?int $userId,
        ?int $replyToMessageId,
        ?string $parseMode,
        bool $disableNotification
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Cannot send an empty MAX message');
        }

        $payload = NewMessageBody::make($text)->notify(!$disableNotification);

        if ($replyToMessageId !== null) {
            $payload = $payload->replyToMessageId((string) $replyToMessageId);
        }

        $format = $this->normalizeParseMode($parseMode);
        if ($format !== null) {
            $payload = $payload->format($format);
        }

        $message = $this->api->messages->sendMessage(
            $payload,
            chatId: $chatId,
            userId: $userId
        );

        return $this->normalizeMessage($message);
    }

    private function extractUploadToken(array $uploadResult, ?string $fallbackToken): ?string
    {
        $candidates = [
            $uploadResult['token'] ?? null,
            $uploadResult['file_token'] ?? null,
            $uploadResult['payload']['token'] ?? null,
            $uploadResult['file']['token'] ?? null,
            $fallbackToken,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function sendAttachmentMessageWithRetry(NewMessageBody $payload, int $chatId): object
    {
        $attempts = 5;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->api->messages->sendMessage($payload, chatId: $chatId, userId: null);
            } catch (ApiException $exception) {
                if (!$this->isAttachmentNotReady($exception) || $attempt === $attempts) {
                    throw $exception;
                }

                usleep(500000);
            }
        }

        throw new RuntimeException('MAX attachment send retry loop exited unexpectedly');
    }

    private function isAttachmentNotReady(ApiException $exception): bool
    {
        $body = $exception->responseBody();
        if (!is_array($body)) {
            return false;
        }

        return trim((string) ($body['code'] ?? '')) === 'attachment.not.ready';
    }

    private function normalizeParseMode(?string $parseMode): ?string
    {
        $mode = strtolower(trim((string) $parseMode));

        return $mode === '' ? null : $mode;
    }

    /**
     * @return array{type: 'chat'|'user', id: int}
     */
    private function normalizeTarget(int|string $chatId): array
    {
        if (is_string($chatId)) {
            $normalized = trim($chatId);
            if (preg_match('/^user:(\d+)$/', $normalized, $matches) === 1) {
                return [
                    'type' => 'user',
                    'id' => (int) $matches[1],
                ];
            }

            if (preg_match('/^chat:(\d+)$/', $normalized, $matches) === 1) {
                return [
                    'type' => 'chat',
                    'id' => (int) $matches[1],
                ];
            }

            if (ctype_digit($normalized)) {
                return [
                    'type' => 'chat',
                    'id' => (int) $normalized,
                ];
            }
        }

        return [
            'type' => 'chat',
            'id' => (int) $chatId,
        ];
    }

}
