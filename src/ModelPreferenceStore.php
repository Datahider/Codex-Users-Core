<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class ModelPreferenceStore
{
    public function __construct(private JsonFileStore $store, private Config $config)
    {
    }

    public function defaultModel(): string
    {
        $model = trim((string) $this->config->require('codex', 'model'));
        if ($model === '') {
            throw new RuntimeException('Default Codex model is empty');
        }

        return $model;
    }

    public function effectiveForRuntimeSession(string $runtime_session_id): string
    {
        $runtime_session_id = $this->requireRuntimeSessionId($runtime_session_id);
        $state = $this->store->read();
        $model = trim((string) ($state['session_models'][$runtime_session_id] ?? ''));

        return $model !== '' ? $model : $this->defaultModel();
    }

    public function setSession(string $runtime_session_id, string $model): void
    {
        $runtime_session_id = $this->requireRuntimeSessionId($runtime_session_id);
        $model = trim($model);
        if ($model === '') {
            throw new RuntimeException('Codex model is required');
        }

        $this->store->update(static function (array $state) use ($runtime_session_id, $model): array {
            $state['session_models'] ??= [];
            $state['session_models'][$runtime_session_id] = $model;
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
