<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return time();
    }
}
