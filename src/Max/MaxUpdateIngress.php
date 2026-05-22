<?php

declare(strict_types=1);

namespace CodexRuntime\Max;

use CodexRuntime\Config;
use CodexRuntime\ControlIngress;
use CodexRuntime\Logger;
use CodexRuntime\TransportInboundMessage;
use CodexRuntime\TransportMessageIngress;

final class MaxUpdateIngress
{
    public function __construct(
        private Config $config,
        private Logger $logger,
        private TransportMessageIngress $ingress,
        private ControlIngress $control,
        private MaxUpdateNormalizer $normalizer,
        private MaxTransportClient $transport,
        private MaxTransportStateStore $routes,
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
            $this->logger->debug('Ignored MAX update', [
                'type' => $payload['update_type'] ?? $payload['type'] ?? $payload['event_type'] ?? 'unknown',
            ]);

            return [
                'accepted' => false,
                'event_id' => null,
                'reason' => 'unsupported_update',
            ];
        }

        if (!$this->isAuthorizedChannel($message->channelId)) {
            $this->logger->info('Rejected MAX update from unauthorized channel', [
                'channel_id' => $message->channelId,
            ]);

            return [
                'accepted' => false,
                'event_id' => null,
                'reason' => 'unauthorized_channel',
            ];
        }

        $sessionId = MaxSessionId::fromChannel($this->transportInstanceId, $message->channelId, $message->channelType);
        if ($this->handleStatusCommand($message->channelId, $message->channelType, $sessionId, $message->text)) {
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

        $ingressResult = $this->ingress->enqueueUserMessage(new TransportInboundMessage(
            channelId: $message->channelId,
            text: $message->text,
            sessionId: $sessionId,
            channelType: $message->channelType,
            replyToMessageId: $message->replyToMessageId,
            threadId: $message->threadId,
            transportMessageId: $message->transportMessageId,
            meta: $message->meta,
        ));
        $actionText = trim((string) ($ingressResult['action_text'] ?? ''));
        if ($actionText !== '') {
            $this->transport->sendMessageToChat(
                (int) $message->channelId,
                $actionText,
                null,
                null,
                true
            );
        }

        $this->logger->info('Forwarded MAX inbound message to Router', [
            'channel_id' => $message->channelId,
            'session_id' => $sessionId,
            'event_id' => $ingressResult['event_id'] ?? null,
            'accepted' => !empty($ingressResult['accepted']),
            'action_text' => $actionText !== '' ? $actionText : null,
            'transport_message_id' => $message->transportMessageId,
        ]);

        return [
            'accepted' => !empty($ingressResult['accepted']),
            'event_id' => $ingressResult['event_id'] ?? null,
            'reason' => $actionText !== '' ? 'router_action' : null,
        ];
    }

    private function handleStatusCommand(int|string $channelId, ?string $channelType, string $sessionId, string $text): bool
    {
        $text = trim($text);
        if ($text === '' || !str_starts_with($text, '/status')) {
            return false;
        }

        $mode = $this->extractStatusMode($text);
        if ($mode === null) {
            $this->sendStatusCommandHelp((int) $channelId, $sessionId, $channelType);

            return true;
        }

        $isDialog = strtolower(trim((string) ($channelType ?? ''))) === 'dialog';
        if ($isDialog && $mode === 'pinned') {
            $this->transport->sendMessageToChat(
                (int) $channelId,
                'Закрепление сообщений в личных чатах не поддерживается MAX.',
                null,
                null,
                true
            );

            return true;
        }

        $this->routes->mergeRoute($sessionId, [
            'channel_id' => (string) $channelId,
            'channel_type' => (string) ($channelType ?? ''),
            'status_mode' => $mode,
            'status_message_id' => $mode === 'pinned' ? ($this->routes->routeForSession($sessionId)['status_message_id'] ?? null) : null,
        ]);

        $reply = match ($mode) {
            'pinned' => 'Статус агента будет отображаться в закреплённом сообщении.',
            'regular' => 'Статус агента будет отправляться обычными сообщениями в общем потоке.',
            default => 'Статус занятости агента отображаться не будет.',
        };

        $this->transport->sendMessageToChat((int) $channelId, $reply, null, null, true);

        return true;
    }

    private function extractStatusMode(string $text): ?string
    {
        $parts = preg_split('/\s+/', trim($text));
        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }

        $mode = strtolower(trim((string) $parts[1]));

        return in_array($mode, ['none', 'pinned', 'regular'], true) ? $mode : null;
    }

    private function sendStatusCommandHelp(int $channelId, string $sessionId, ?string $channelType): void
    {
        $current = strtolower(trim((string) (($this->routes->routeForSession($sessionId)['status_mode'] ?? 'none'))));
        if (!in_array($current, ['none', 'pinned', 'regular'], true)) {
            $current = 'none';
        }

        $text = "Режим статуса для этого чата: {$current}\n\n"
            . "/status none\n"
            . "/status pinned\n"
            . "/status regular";

        $this->transport->sendMessageToChat($channelId, $text, null, null, true);
    }

    private function handleUnknownSlashCommand(object $message, string $sessionId): ?string
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
            meta: $message->meta + ['source' => 'max']
        );
    }

    private function isAuthorizedChannel(int|string $channelId): bool
    {
        $channelKey = (string) $channelId;
        foreach ($this->config->requireList('transport', 'allowed_channel_ids') as $allowedChannelId) {
            if ((string) $allowedChannelId === $channelKey) {
                return true;
            }
        }

        return false;
    }
}
