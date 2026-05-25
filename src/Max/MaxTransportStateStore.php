<?php

declare(strict_types=1);

namespace CodexRuntime\Max;

use CodexRuntime\Config;
use CodexRuntime\JsonFileStore;

final class MaxTransportStateStore
{
    private JsonFileStore $store;

    public function __construct(Config $config)
    {
        $path = (string) $config->get(
            'max',
            'transport_state_file',
            dirname((string) $config->require('storage', 'manager_state_file')) . '/max-transport-state.json'
        );

        $this->store = new JsonFileStore($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function routeForSession(string $sessionId): ?array
    {
        if (trim($sessionId) === '') {
            return null;
        }

        $state = $this->store->read();
        $route = $state['sessions'][$sessionId] ?? null;

        return is_array($route) ? $route : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allRoutes(): array
    {
        $state = $this->store->read();
        $sessions = $state['sessions'] ?? [];

        return is_array($sessions) ? $sessions : [];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function mergeRoute(string $sessionId, array $attributes): void
    {
        if (trim($sessionId) === '') {
            return;
        }

        $state = $this->store->read();
        $existing = $state['sessions'][$sessionId] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        $next = $existing;
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                unset($next[$key]);
                continue;
            }

            $next[$key] = $value;
        }

        $next['updated_at'] = date(DATE_ATOM);

        $state['sessions'][$sessionId] = $next;

        $this->store->write($state);
    }
}
