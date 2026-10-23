<?php

declare(strict_types=1);

namespace CodexRuntime;

interface CodexDeviceAuthInterface
{
    public function isLoggedIn(): bool;

    /** @param callable(string, string): void $challenge */
    public function login(callable $challenge): void;
}
