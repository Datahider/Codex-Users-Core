<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use CodexRuntime\Config;
use CodexRuntime\Contracts\StatusMessageServiceInterface;

final class RouterStatusMessageService implements StatusMessageServiceInterface
{
    public function __construct(
        private Config $config,
        private RouterTransportClient $delivery
    ) {
    }

    public function updateWorkerIdle(?string $runtimeSessionId = null): void
    {
        $this->sendStatus($runtimeSessionId, $this->idleText());
    }

    public function updateWorkerBusy(string $taskId, ?string $runtimeSessionId = null): void
    {
        $runtimeSessionId = trim((string) ($runtimeSessionId ?? ''));
        if ($runtimeSessionId === '') {
            return;
        }

        $this->sendBusyStatus($runtimeSessionId, $taskId, $this->busyText($taskId));
    }

    public function updateWorkerFailed(string $taskId, ?string $runtimeSessionId = null): void
    {
        $runtimeSessionId = trim((string) ($runtimeSessionId ?? ''));
        if ($runtimeSessionId === '') {
            return;
        }

        $this->delivery->sendStatus($runtimeSessionId, $this->failedText($taskId), 'failed', trim($taskId));
    }

    public function sendHeartbeat(?string $runtimeSessionId = null): void
    {
        $runtimeSessionId = trim((string) ($runtimeSessionId ?? ''));
        if ($runtimeSessionId === '') {
            return;
        }

        $this->delivery->sendHeartbeat($runtimeSessionId);
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
        return (string) $this->config->get('manager_queue', 'idle_status_text', 'Idle');
    }

    public function busyText(string $taskId): string
    {
        $template = (string) $this->config->get('manager_queue', 'busy_status_template', 'Busy: %s');

        return sprintf($template, trim($taskId));
    }

    public function failedText(string $taskId): string
    {
        $template = (string) $this->config->get('manager_queue', 'failed_status_template', 'Ошибка выполнения задачи %s');

        return sprintf($template, trim($taskId));
    }

    public function restartText(): string
    {
        return (string) $this->config->get('manager_queue', 'restart_status_text', 'Restarting...');
    }

    public function readyText(): string
    {
        return (string) $this->config->get('manager_queue', 'ready_message_text', 'Ready.');
    }

    private function sendStatus(?string $runtimeSessionId, string $text): void
    {
        $runtimeSessionId = trim((string) ($runtimeSessionId ?? ''));
        $text = trim($text);
        if ($runtimeSessionId === '' || $text === '') {
            return;
        }

        $state = $text === $this->idleText() ? 'idle' : 'custom';
        $this->delivery->sendStatus($runtimeSessionId, $text, $state);
    }

    private function sendBusyStatus(string $runtimeSessionId, string $taskId, string $text): void
    {
        $runtimeSessionId = trim($runtimeSessionId);
        $text = trim($text);
        if ($runtimeSessionId === '' || $text === '') {
            return;
        }

        $this->delivery->sendStatus($runtimeSessionId, $text, 'busy', $taskId);
    }
}
