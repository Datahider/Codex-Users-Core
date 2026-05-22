<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use CodexRuntime\Contracts\TransportIngressGatewayInterface;
use CodexRuntime\TransportInboundMessage;

final class TransportIngressGateway implements TransportIngressGatewayInterface
{
    public function __construct(private ApiClient $api)
    {
    }

    public function submitMessage(TransportInboundMessage $message): array
    {
        $response = $this->api->postJson('/api/v1/transport/ingress', [
            'runtime_session_id' => (string) ($message->sessionId ?? ''),
            'kind' => 'message',
            'text' => trim($message->text),
            'attachments' => [],
            'meta' => $message->meta,
        ]);

        $action = is_array($response['action'] ?? null) ? $response['action'] : [];
        $actionText = trim((string) ($action['text'] ?? ''));

        return [
            'accepted' => !empty($response['accepted']),
            'event_id' => $response['event_id'] ?? null,
            'action_text' => $actionText !== '' ? $actionText : null,
        ];
    }
}
