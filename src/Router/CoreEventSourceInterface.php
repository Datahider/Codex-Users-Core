<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

interface CoreEventSourceInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function pollNextEvent(int $afterId, int $wait, int $limit = 1): ?array;
}
