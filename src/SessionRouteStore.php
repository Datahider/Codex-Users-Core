<?php

declare(strict_types=1);

namespace CodexRuntime;

final class SessionRouteStore
{
    private JsonFileStore $store;

    public function __construct(Config $config)
    {
        $this->store = new JsonFileStore((string) $config->require('storage', 'manager_state_file'));
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

        $state['sessions'][$sessionId] = array_merge($existing, $attributes, [
            'updated_at' => date(DATE_ATOM),
        ]);

        $this->store->write($state);
    }
}
