<?php

declare(strict_types=1);

namespace CodexRuntime\Telegram;

use CodexRuntime\Config;
use CodexRuntime\ControlIngress;
use CodexRuntime\Logger;
use CodexRuntime\TransportInboundMessage;
use CodexRuntime\TransportMessageIngress;

final class TelegramUpdateIngress
{
    public function __construct(
        private Config $config,
        private Logger $logger,
        private TransportMessageIngress $ingress,
        private ControlIngress $control,
        private TelegramUpdateNormalizer $normalizer,
        private TelegramApiClient $api,
        private string $transportInstanceId
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{accepted: bool, event_id: ?string, reason: ?string}
     */
    public function ingest(array $payload): array
    {
        $message = $this->normalizer->normalize($payload);
        if ($message === null) {
            $this->logger->debug('Ignored Telegram update', [
                'update_id' => $payload['update_id'] ?? null,
                'reason' => 'unsupported_update',
            ]);

            return [
                'accepted' => false,
                'event_id' => null,
                'reason' => 'unsupported_update',
            ];
        }

        if (!$this->isAuthorizedChannel($message->channelId)) {
            $this->logger->info('Rejected Telegram update from unauthorized channel', [
                'channel_id' => $message->channelId,
            ]);

            return [
                'accepted' => false,
                'event_id' => null,
                'reason' => 'unauthorized_channel',
            ];
        }

        $sessionId = TelegramSessionId::fromChat(
            $this->transportInstanceId,
            $message->channelId,
            $message->channelType,
            $message->threadId
        );

        if ($this->handleStatusCommand($message, $sessionId)) {
            return [
                'accepted' => true,
                'event_id' => null,
                'reason' => 'status_command',
            ];
        }

        $controlCommandId = $this->handleUnknownSlashCommand($message, $sessionId);
        if ($controlCommandId !== null) {
            return [
                'accepted' => true,
                'event_id' => $controlCommandId,
                'reason' => 'control_command',
            ];
        }

        $eventId = $this->ingress->enqueueUserMessage(new TransportInboundMessage(
            channelId: $message->channelId,
            text: $message->text,
            sessionId: $sessionId,
            channelType: $message->channelType,
            replyToMessageId: $message->replyToMessageId,
            threadId: $message->threadId,
            transportMessageId: $message->transportMessageId,
            meta: $message->meta,
        ));

        $this->logger->info('Enqueued Telegram inbound message', [
            'channel_id' => $message->channelId,
            'session_id' => $sessionId,
            'event_id' => $eventId,
            'transport_message_id' => $message->transportMessageId,
        ]);

        return [
            'accepted' => true,
            'event_id' => $eventId,
            'reason' => null,
        ];
    }

    private function handleStatusCommand(TransportInboundMessage $message, string $sessionId): bool
    {
        $text = trim($message->text);
        if ($text === '' || !preg_match('/^\/status(?:@\S+)?(?:\s|$)/ui', $text)) {
            return false;
        }

        $this->api->sendMessage(
            $message->channelId,
            'Команда /status обрабатывается в Telegram transport, но режимы статуса для него пока не реализованы.',
            $message->transportMessageId !== null ? (int) $message->transportMessageId : null,
            null,
            true,
            $message->threadId
        );

        $this->logger->info('Handled Telegram transport status command', [
            'channel_id' => $message->channelId,
            'session_id' => $sessionId,
            'transport_message_id' => $message->transportMessageId,
        ]);

        return true;
    }

    private function handleUnknownSlashCommand(TransportInboundMessage $message, string $sessionId): ?string
    {
        $text = trim($message->text);
        if ($text === '' || !preg_match('/^\/[^\s]+/u', $text)) {
            return null;
        }

        return $this->control->enqueueTransportCommand(
            channelId: $message->channelId,
            text: $text,
            sessionId: $sessionId,
            channelType: $message->channelType,
            replyToMessageId: $message->replyToMessageId,
            threadId: $message->threadId,
            transportMessageId: $message->transportMessageId,
            meta: $message->meta + ['source' => 'telegram']
        );
    }

    private function isAuthorizedChannel(int|string $channelId): bool
    {
        $channelKey = (string) $channelId;
        foreach ($this->config->requireList('telegram', 'allowed_chat_ids') as $allowedChatId) {
            if ((string) $allowedChatId === $channelKey) {
                return true;
            }
        }

        return false;
    }
}
