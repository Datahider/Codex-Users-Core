<?php

declare(strict_types=1);

namespace CodexRuntime\Max;

use CodexRuntime\Config;
use CodexRuntime\Contracts\StatusMessageServiceInterface;
use CodexRuntime\Contracts\TransportClientInterface;

final class MaxStatusMessageService implements StatusMessageServiceInterface
{
    public function __construct(
        private Config $config,
        private TransportClientInterface $transport
    ) {
    }

    public function updateStatus(string $text): void
    {
    }

    public function updateWorkerIdle(?string $runtimeSessionId = null): void
    {
        $this->updateStatus($this->idleText());
    }

    public function updateWorkerBusy(string $taskId, ?string $runtimeSessionId = null): void
    {
        $this->updateStatus($this->busyText($taskId));
    }

    public function updateWorkerFailed(string $taskId, ?string $runtimeSessionId = null): void
    {
        $this->updateStatus($this->failedText($taskId));
    }

    public function sendHeartbeat(?string $runtimeSessionId = null): void
    {
    }

    public function forceUpdateStatus(string $text): void
    {
        $this->notifyAll($text);
    }

    public function notifyAll(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        foreach ($this->statusChannelIds() as $channelId) {
            $this->transport->sendMessage($channelId, $text, null, 'HTML', true);
        }
    }

    public function idleText(): string
    {
        return (string) $this->config->get('manager_queue', 'idle_status_text', 'Idle');
    }

    public function busyText(string $taskId): string
    {
        $template = (string) $this->config->get('manager_queue', 'busy_status_template', 'Busy: %s');

        return sprintf($template, $taskId);
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

    /**
     * @return list<int|string>
     */
    private function statusChannelIds(): array
    {
        $channelIds = $this->config->get('max', 'status_channel_ids', []);
        if (!is_array($channelIds)) {
            return [];
        }

        return array_values(array_filter(
            $channelIds,
            static fn (mixed $channelId): bool => $channelId !== null && $channelId !== ''
        ));
    }
}
