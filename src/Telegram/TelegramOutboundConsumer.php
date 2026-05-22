<?php

declare(strict_types=1);

namespace CodexRuntime\Telegram;

use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\OutboundQueue\MessageRepository;
use Throwable;

final class TelegramOutboundConsumer
{
    private readonly TelegramTextRenderer $renderer;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private MessageRepository $messages,
        private TelegramApiClient $api,
        private string $transportInstanceId
    ) {
        $this->renderer = new TelegramTextRenderer();
    }

    public function run(): void
    {
        $pollIntervalMs = max(100, (int) $this->config->require('transport', 'outbound_poll_interval_ms'));

        $this->logger->info('Telegram outbound consumer started');

        while (true) {
            $handled = false;

            foreach ($this->messages->listPendingPaths() as $path) {
                $message = $this->messages->loadMessageIfPresent($path);
                if ($message === null) {
                    continue;
                }

                if (!$this->canHandle($message)) {
                    continue;
                }

                $handled = true;

                try {
                    $this->dispatch($message);
                    $this->messages->markDone($path);
                } catch (Throwable $e) {
                    $context = [
                        'message_id' => (string) ($message['id'] ?? ''),
                        'session_id' => (string) ($message['session_id'] ?? ''),
                        'type' => (string) ($message['type'] ?? ''),
                        'error' => $e->getMessage(),
                    ];
                    $this->logger->error('Telegram outbound dispatch failed', $context + ['payload' => $message]);
                    $this->messages->markFailed($path, $message, $e->getMessage(), $context);
                }
            }

            if (!$handled) {
                usleep($pollIntervalMs * 1000);
            }
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function canHandle(array $message): bool
    {
        $sessionId = trim((string) ($message['session_id'] ?? ''));
        if ($sessionId === '') {
            return false;
        }

        return TelegramSessionId::resolve($sessionId, $this->transportInstanceId) !== null;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function dispatch(array $message): void
    {
        $sessionId = trim((string) ($message['session_id'] ?? ''));
        $target = TelegramSessionId::resolve($sessionId, $this->transportInstanceId);
        if ($target === null) {
            return;
        }

        $chatId = (int) $target['chat_id'];
        $threadId = $target['thread_id'];
        $type = (string) ($message['type'] ?? 'message');

        if ($type === 'chat_action') {
            $result = $this->api->sendChatAction(
                $chatId,
                (string) ($message['action'] ?? 'typing'),
                $threadId
            );

            $this->logger->debug('Telegram chat action sent', [
                'session_id' => $sessionId,
                'target' => $chatId,
                'thread_id' => $threadId,
                'action' => (string) ($message['action'] ?? 'typing'),
                'result' => $result,
            ]);

            return;
        }

        if ($type !== 'message') {
            return;
        }

        [$text, $parseMode] = $this->renderMessage(
            (string) ($message['kind'] ?? ''),
            (string) ($message['text'] ?? '')
        );

        $result = $this->api->sendMessage(
            $chatId,
            $text,
            null,
            $parseMode,
            $this->shouldDisableNotification($message),
            $threadId
        );

        $this->logger->info('Telegram outbound message sent', [
            'session_id' => $sessionId,
            'target' => $chatId,
            'thread_id' => $threadId,
            'message_id' => $message['id'] ?? null,
            'result_message_id' => $result['result']['message_id'] ?? null,
        ]);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function renderMessage(string $kind, string $text): array
    {
        return match ($kind) {
            'commentary' => [$this->renderer->renderCommentary($text), 'HTML'],
            default => ['✅ ' . ltrim($this->renderer->renderFinal($text)), 'HTML'],
        };
    }

    /**
     * @param array<string, mixed> $message
     */
    private function shouldDisableNotification(array $message): bool
    {
        return ((string) ($message['kind'] ?? '')) === 'commentary';
    }
}
