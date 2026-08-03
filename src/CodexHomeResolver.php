<?php

declare(strict_types=1);

namespace CodexRuntime;

final class CodexHomeResolver
{
    public function resolve(?string $explicitCodexHome = null): ?string
    {
        $explicitCodexHome = trim((string) $explicitCodexHome);
        if ($explicitCodexHome !== '') {
            return rtrim($explicitCodexHome, '/');
        }

        $envCodexHome = trim((string) getenv('CODEX_HOME'));
        if ($envCodexHome !== '') {
            return rtrim($envCodexHome, '/');
        }

        $homeDirectory = rtrim(trim((string) getenv('HOME')), '/');
        if ($homeDirectory === '') {
            return null;
        }

        foreach ([
            $homeDirectory . '/.codex',
            $homeDirectory . '/snap/codex/current',
        ] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        $glob = glob($homeDirectory . '/snap/codex/*', GLOB_ONLYDIR);
        if (is_array($glob) && $glob !== []) {
            rsort($glob, SORT_STRING);
            foreach ($glob as $candidate) {
                if (is_dir($candidate)) {
                    return rtrim($candidate, '/');
                }
            }
        }

        return null;
    }
}
