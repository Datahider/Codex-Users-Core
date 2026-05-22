<?php

declare(strict_types=1);

namespace CodexRuntime\Telegram;

use RuntimeException;

final class TelegramApiClient
{
    private string $baseUrl;

    public function __construct(
        string $botToken,
        string $baseUrl = 'https://api.telegram.org',
        string $endpointPrefix = 'bot'
    )
    {
        $token = trim($botToken);
        if ($token === '') {
            throw new RuntimeException('Telegram bot token is required');
        }

        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Telegram API base URL is required');
        }

        $endpointPrefix = trim($endpointPrefix, '/');
        $path = $baseUrl . '/';
        if ($endpointPrefix !== '') {
            $path .= $endpointPrefix;
        }

        $this->baseUrl = $path . $token . '/';
    }

    /**
     * @return array<string, mixed>
     */
    public function getMe(): array
    {
        return $this->request('getMe');
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?int $replyToMessageId = null,
        ?string $parseMode = null,
        bool $disableNotification = false,
        ?int $messageThreadId = null
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Telegram outbound message text cannot be empty');
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_notification' => $disableNotification,
        ];

        if ($parseMode !== null && trim($parseMode) !== '') {
            $payload['parse_mode'] = trim($parseMode);
        }

        if ($messageThreadId !== null && $messageThreadId > 0) {
            $payload['message_thread_id'] = $messageThreadId;
        }

        if ($replyToMessageId !== null && $replyToMessageId > 0) {
            $payload['reply_parameters'] = [
                'message_id' => $replyToMessageId,
            ];
        }

        return $this->request('sendMessage', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendChatAction(
        int|string $chatId,
        string $action = 'typing',
        ?int $messageThreadId = null
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'action' => trim($action) !== '' ? trim($action) : 'typing',
        ];

        if ($messageThreadId !== null && $messageThreadId > 0) {
            $payload['message_thread_id'] = $messageThreadId;
        }

        return $this->request('sendChatAction', $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUpdates(?int $offset, int $timeout, int $limit = 100): array
    {
        $payload = [
            'timeout' => max(0, min(50, $timeout)),
            'limit' => max(1, min(100, $limit)),
            'allowed_updates' => ['message'],
        ];

        if ($offset !== null && $offset > 0) {
            $payload['offset'] = $offset;
        }

        $result = $this->request('getUpdates', $payload);
        $updates = $result['result'] ?? [];

        return is_array($updates) ? array_values(array_filter($updates, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, array $payload = []): array
    {
        $ch = curl_init($this->baseUrl . $method);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialize Telegram API request');
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Cannot encode Telegram API payload');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => max(10, (($payload['timeout'] ?? 0) + 10)),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Telegram API request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new RuntimeException('Telegram API returned invalid JSON');
        }

        if ($statusCode >= 400) {
            $description = (string) ($response['description'] ?? 'HTTP ' . $statusCode);
            throw new RuntimeException('Telegram API request failed: ' . $description);
        }

        if (($response['ok'] ?? false) !== true) {
            $description = (string) ($response['description'] ?? 'request failed');
            throw new RuntimeException('Telegram API request failed: ' . $description);
        }

        return $response;
    }
}
