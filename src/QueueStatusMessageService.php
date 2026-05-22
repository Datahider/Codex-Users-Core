<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\StatusMessageServiceInterface;
use CodexRuntime\OutboundQueue\MessageRepository;

final class QueueStatusMessageService implements StatusMessageServiceInterface
{
    public function __construct(
        private Config $config,
        private MessageRepository $messages
    ) {
    }

    public function updateWorkerIdle(?string $runtimeSessionId = null): void
    {
        $this->enqueueStatus('idle', null, $this->idleText(), $runtimeSessionId);
    }

    public function updateWorkerBusy(string $taskId, ?string $runtimeSessionId = null): void
    {
        $taskId = trim($taskId);
        $this->enqueueStatus('busy', $taskId !== '' ? $taskId : null, $this->busyText($taskId), $runtimeSessionId);
    }

    public function updateStatus(string $text): void
    {
        $this->enqueueStatus('custom', null, $text);
    }

    public function forceUpdateStatus(string $text): void
    {
        $this->enqueueStatus('custom', null, $text);
    }

    public function notifyAll(string $text): void
    {
        $this->enqueueStatus('custom', null, $text);
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

    private function enqueueStatus(string $state, ?string $taskId, string $text, ?string $runtimeSessionId = null): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $runtimeSessionId = trim((string) ($runtimeSessionId ?? ''));
        if ($runtimeSessionId === '') {
            return;
        }

        $this->messages->enqueue([
            'type' => 'status',
            'kind' => 'status',
            'session_id' => $runtimeSessionId,
            'state' => $state,
            'job_id' => $taskId,
            'text' => $text,
        ]);
    }
}
