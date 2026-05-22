<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\StatusMessageServiceInterface;

final class NoopStatusMessageService implements StatusMessageServiceInterface
{
    public function updateWorkerIdle(?string $runtimeSessionId = null): void
    {
    }

    public function updateWorkerBusy(string $taskId, ?string $runtimeSessionId = null): void
    {
    }

    public function updateStatus(string $text): void
    {
    }

    public function forceUpdateStatus(string $text): void
    {
    }

    public function notifyAll(string $text): void
    {
    }

    public function idleText(): string
    {
        return 'Idle';
    }

    public function busyText(string $summary): string
    {
        return 'Busy: ' . trim($summary);
    }

    public function restartText(): string
    {
        return 'Restarting...';
    }

    public function readyText(): string
    {
        return 'Ready.';
    }
}
