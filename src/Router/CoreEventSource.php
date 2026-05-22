<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

final class CoreEventSource
{
    public function __construct(private ApiClient $api)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pollNextEvent(int $afterId, int $wait, int $limit = 1): ?array
    {
        $response = $this->api->getJson('/api/v1/core/events', [
            'after_id' => $afterId,
            'wait' => $wait,
            'limit' => $limit,
        ]);

        $events = $response['events'] ?? null;
        if (!is_array($events) || $events === []) {
            return null;
        }

        $event = $events[0] ?? null;
        if (!is_array($event)) {
            return null;
        }

        return [
            'id' => 'router:' . (string) ($event['event_id'] ?? ''),
            'router_event_id' => (int) ($event['event_id'] ?? 0),
            'type' => 'user_message',
            'priority' => 50,
            'session_id' => (string) ($event['runtime_session_id'] ?? ''),
            'text' => trim((string) ($event['text'] ?? '')),
            'meta' => [
                'source' => 'router',
                'transport_instance_id' => (string) ($event['transport_instance_id'] ?? ''),
                'router_kind' => (string) ($event['kind'] ?? ''),
                'attachments' => is_array($event['attachments'] ?? null) ? $event['attachments'] : [],
                'router_meta' => is_array($event['meta'] ?? null) ? $event['meta'] : [],
            ],
        ];
    }
}
