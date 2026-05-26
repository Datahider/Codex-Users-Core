<?php

declare(strict_types=1);

namespace CodexRuntime\Contracts;

interface StatusMessageServiceInterface
{
    public function updateWorkerIdle(?string $runtimeSessionId = null): void;

    public function updateWorkerBusy(string $taskId, ?string $runtimeSessionId = null): void;

    public function updateWorkerFailed(string $taskId, ?string $runtimeSessionId = null): void;

    public function sendHeartbeat(?string $runtimeSessionId = null): void;

    public function updateStatus(string $text): void;

    public function forceUpdateStatus(string $text): void;

    public function notifyAll(string $text): void;

    public function idleText(): string;

    public function busyText(string $taskId): string;

    public function failedText(string $taskId): string;

    public function restartText(): string;

    public function readyText(): string;
}
