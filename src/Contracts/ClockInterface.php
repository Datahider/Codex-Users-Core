<?php

declare(strict_types=1);

namespace CodexRuntime\Contracts;

interface ClockInterface
{
    public function now(): int;
}
