<?php

declare(strict_types=1);

namespace CodexRuntime;

interface ClaudeSubscriptionAuthInterface
{
    public function isLoggedIn(): bool;

    public function hasPendingLogin(): bool;

    /** @param callable(string): void $authorization */
    public function beginLogin(callable $authorization): void;

    public function completeLogin(string $code): void;
}
