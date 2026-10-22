<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class ProviderPreferenceStore
{
    private ProviderAvailability $availability;

    public function __construct(private JsonFileStore $store, Config $config)
    {
        $this->availability = new ProviderAvailability($config);
    }

    public function effectiveForRuntimeSession(string $runtime_session_id): string
    {
        return self::effectiveFromState($this->store->read(), $this->requireRuntimeSessionId($runtime_session_id), $this->availability);
    }

    public function isStarted(string $runtime_session_id): bool
    {
        return self::isStartedInState($this->store->read(), $this->requireRuntimeSessionId($runtime_session_id));
    }

    public function setSession(string $runtime_session_id, string $provider): void
    {
        $runtime_session_id = $this->requireRuntimeSessionId($runtime_session_id);
        if (!$this->availability->isKnown($provider)) {
            throw new RuntimeException("Unknown provider: {$provider}");
        }

        $this->store->update(static function (array $state) use ($runtime_session_id, $provider): array {
            if (self::isStartedInState($state, $runtime_session_id)) {
                throw new RuntimeException('Runtime session is already started');
            }
            $state['session_providers'] ??= [];
            $state['session_providers'][$runtime_session_id] = $provider;
            return $state;
        });
    }

    /**
     * Эффективный провайдер по уже прочитанному state.
     * Начатая сессия без записанного провайдера создана до появления провайдеров и считается codex.
     *
     * @param array<string, mixed> $state
     */
    public static function effectiveFromState(array $state, string $runtime_session_id, ProviderAvailability $availability): string
    {
        $provider = trim((string) ($state['session_providers'][$runtime_session_id] ?? ''));
        if ($provider !== '') {
            return $provider;
        }

        if (self::isStartedInState($state, $runtime_session_id)) {
            return ProviderAvailability::CODEX;
        }

        return $availability->defaultProvider();
    }

    /** @param array<string, mixed> $state */
    public static function isStartedInState(array $state, string $runtime_session_id): bool
    {
        $route = $state['sessions'][$runtime_session_id] ?? null;
        if (is_array($route)) {
            return trim((string) ($route['codex_session_id'] ?? '')) !== '';
        }

        return trim((string) $route) !== '';
    }

    private function requireRuntimeSessionId(string $runtime_session_id): string
    {
        $runtime_session_id = trim($runtime_session_id);
        if ($runtime_session_id === '') {
            throw new RuntimeException('Runtime session id is required');
        }

        return $runtime_session_id;
    }
}
