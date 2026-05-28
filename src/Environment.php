<?php

declare(strict_types=1);

namespace CodexRuntime;

final class Environment
{
    public static function homeDirectory(): ?string
    {
        $home = getenv('HOME');
        if (is_string($home) && trim($home) !== '') {
            return rtrim($home, '/');
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user) && is_string($user['dir'] ?? null) && trim($user['dir']) !== '') {
                return rtrim((string) $user['dir'], '/');
            }
        }

        return null;
    }

    public static function resolveCommand(string $command): ?string
    {
        $command = trim($command);
        if ($command === '') {
            return null;
        }

        if (str_contains($command, '/')) {
            return is_executable($command) ? $command : null;
        }

        $path = getenv('PATH');
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }

            $candidate = rtrim($dir, '/') . '/' . $command;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
