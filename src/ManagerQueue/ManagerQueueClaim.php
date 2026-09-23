<?php

declare(strict_types=1);

namespace CodexRuntime\ManagerQueue;

final class ManagerQueueClaim
{
    public function __construct(
        public readonly string $running_path,
        public readonly array $event,
        public readonly string $session_id,
        private ManagerSessionLock $session_lock
    ) {
    }

    public function release(): void
    {
        $this->session_lock->release();
    }
}
