<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class CodexModelCatalog
{
    public function __construct(private Config $config)
    {
    }

    /** @return list<string> */
    public function available(): array
    {
        $process = proc_open(
            [
                (string) $this->config->get('codex', 'bin', 'codex'),
                'debug',
                'models',
                '--bundled',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            (string) $this->config->require('codex', 'cwd')
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start Codex model catalog command');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
        if ($exit_code !== 0) {
            throw new RuntimeException('Codex model catalog command failed: ' . trim((string) $stderr));
        }

        $payload = json_decode((string) $stdout, true);
        if (!is_array($payload) || !is_array($payload['models'] ?? null)) {
            throw new RuntimeException('Codex model catalog response is invalid');
        }

        $models = [];
        foreach ($payload['models'] as $model) {
            $slug = trim((string) (is_array($model) ? ($model['slug'] ?? '') : ''));
            if ($slug !== '' && !in_array($slug, $models, true)) {
                $models[] = $slug;
            }
        }
        if ($models === []) {
            throw new RuntimeException('Codex model catalog is empty');
        }

        return $models;
    }
}
