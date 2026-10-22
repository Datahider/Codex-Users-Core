<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class ModelPreferenceStore
{
    public const FALLBACK_MODEL = 'gpt-5.6-sol';

    public function __construct(private JsonFileStore $store, private Config $config)
    {
    }

    public function defaultModel(): string
    {
        $model = trim((string) $this->config->get('codex', 'model', ''));

        return $model !== '' ? $model : self::FALLBACK_MODEL;
    }

    public function defaultModelForProvider(string $provider): string
    {
        if ($provider === ProviderAvailability::CODEX) {
            return $this->defaultModel();
        }

        if ($provider === ProviderAvailability::CLAUDE) {
            $model = trim((string) $this->config->get('claude', 'model', ''));
            if ($model === '') {
                throw new RuntimeException('claude.model is not configured');
            }

            return $model;
        }

        throw new RuntimeException("Unknown provider: {$provider}");
    }

    public function effectiveForRuntimeSession(string $runtime_session_id): string
    {
        $runtime_session_id = $this->requireRuntimeSessionId($runtime_session_id);
        $state = $this->store->read();
        $model = trim((string) ($state['session_models'][$runtime_session_id] ?? ''));
        if ($model !== '') {
            return $model;
        }

        $provider = ProviderPreferenceStore::effectiveFromState($state, $runtime_session_id, new ProviderAvailability($this->config));

        return $this->defaultModelForProvider($provider);
    }

    public function setSession(string $runtime_session_id, string $model): void
    {
        $runtime_session_id = $this->requireRuntimeSessionId($runtime_session_id);
        $model = trim($model);
        if ($model === '') {
            throw new RuntimeException('Model is required');
        }

        $this->store->update(static function (array $state) use ($runtime_session_id, $model): array {
            $state['session_models'] ??= [];
            $state['session_models'][$runtime_session_id] = $model;
            return $state;
        });
    }

    public function clearSession(string $runtime_session_id): void
    {
        $runtime_session_id = $this->requireRuntimeSessionId($runtime_session_id);
        $this->store->update(static function (array $state) use ($runtime_session_id): array {
            unset($state['session_models'][$runtime_session_id]);
            return $state;
        });
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
