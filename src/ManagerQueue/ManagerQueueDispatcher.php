<?php

declare(strict_types=1);

namespace CodexRuntime\ManagerQueue;

use CodexRuntime\Config;

final class ManagerQueueDispatcher
{
    public function __construct(private Config $config, private EventRepository $events)
    {
    }

    public function claimNext(): ?ManagerQueueClaim
    {
        foreach ($this->events->pendingPaths() as $path) {
            $event = $this->events->loadEvent($path);
            $session_id = $this->sessionId($event);
            $lock_key = $session_id !== '' ? $session_id : '__missing__:' . (string) ($event['id'] ?? basename($path));
            $session_lock = new ManagerSessionLock($this->config, $lock_key);
            if (!$session_lock->acquire()) {
                continue;
            }

            $running_path = $this->events->tryMoveToRunning($path);
            if ($running_path === null) {
                $session_lock->release();
                continue;
            }

            return new ManagerQueueClaim($running_path, $event, $session_id, $session_lock);
        }

        return null;
    }

    /** @return array{running_path:string,event:array<string,mixed>}|null */
    public function claimNextForSession(string $session_id): ?array
    {
        foreach ($this->events->pendingPaths() as $path) {
            $event = $this->events->loadEvent($path);
            if ($this->sessionId($event) !== $session_id) {
                continue;
            }
            $running_path = $this->events->tryMoveToRunning($path);
            if ($running_path === null) {
                continue;
            }
            return ['running_path' => $running_path, 'event' => $event];
        }
        return null;
    }

    private function sessionId(array $event): string
    {
        return trim((string) ($event['session_id'] ?? $event['meta']['session_id'] ?? ''));
    }
}
