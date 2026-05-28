<?php

declare(strict_types=1);

namespace CodexRuntime;

final class ConfigPathResolver
{
    /**
     * @param list<string> $argv
     */
    public static function resolve(array $argv, string $scriptDir): string
    {
        if (isset($argv[1]) && trim($argv[1]) !== '') {
            return $argv[1];
        }

        $home = Environment::homeDirectory();
        if ($home !== null) {
            $userConfig = $home . '/.codex-users-core/config.php';
            if (is_file($userConfig)) {
                return $userConfig;
            }
        }

        return dirname(rtrim($scriptDir, '/')) . '/config/config.php';
    }
}
