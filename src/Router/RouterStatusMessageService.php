<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use CodexRuntime\Config;
use CodexRuntime\Contracts\StatusMessageServiceInterface;
use CodexRuntime\Contracts\TransportClientInterface;

final class RouterStatusMessageService implements StatusMessageServiceInterface
{
    public function __construct(
        private Config $config,
        private TransportClientInterface $delivery
    ) {
    }

    public function updateWorkerIdle(?string $runtimeSessionId = null): void
    {
        $this->sendStatus($runtimeSessionId, $this->idleText());
    }

    public function updateWorkerBusy(string $taskId, ?string $runtimeSessionId = null): void
    {
        $this->sendStatus($runtimeSessionId, $this->busyText($taskId));
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

        $this->delivery->sendMessage($runtimeSessionId, $text, null, null, true);
    }
}
