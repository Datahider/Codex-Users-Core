<?php

declare(strict_types=1);

namespace CodexRuntime\Mcp;

interface CoreEnvironmentToolInterface
{
    /** @return array<string, mixed> */
    public function describe(): array;
}
