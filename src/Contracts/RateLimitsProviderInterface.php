<?php

declare(strict_types=1);

namespace CodexRuntime\Contracts;

interface RateLimitsProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function read(): array;
}
