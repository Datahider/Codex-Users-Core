<?php

declare(strict_types=1);

namespace CodexRuntime\Telegram;

use CodexRuntime\Config;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use Throwable;

final class TelegramLongPollingRunner
{
    public function __construct(
        private Config $config,
        private Logger $logger,
        private TelegramApiClient $api,
        private TelegramUpdateIngress $ingress,
        private JsonFileStore $state
    ) {
    }

    public function run(): void
    {
        $timeout = $this->pollTimeoutSeconds();
        $retryDelayMs = $this->retryDelayMs();
        $offset = $this->readOffset();

        $me = $this->api->getMe();
        $this->logger->info('Starting Telegram long polling', [
            'timeout_seconds' => $timeout,
            'limit' => $this->pollLimit(),
            'offset' => $offset,
            'bot_id' => $me['result']['id'] ?? null,
            'bot_username' => $me['result']['username'] ?? null,
        ]);

        while (true) {
            try {
                $offset = $this->processBatch($offset);
            } catch (Throwable $e) {
                $this->logger->error('Telegram long polling batch failed', [
                    'message' => $e->getMessage(),
                    'offset' => $offset,
                ]);
                usleep($retryDelayMs * 1000);
            }
        }
    }

    public function processBatch(?int $offset): ?int
    {
        $updates = $this->api->getUpdates($offset, $this->pollTimeoutSeconds(), $this->pollLimit());
        $nextOffset = $offset;

        foreach ($updates as $update) {
            $this->ingress->ingest($update);

            $updateId = $update['update_id'] ?? null;
            if (is_numeric($updateId)) {
                $candidate = (int) $updateId + 1;
                if ($nextOffset === null || $candidate > $nextOffset) {
                    $nextOffset = $candidate;
                }
            }
        }

        if ($nextOffset !== $offset) {
            $this->writeOffset($nextOffset);
        }

        return $nextOffset;
    }

    private function readOffset(): ?int
    {
        $state = $this->state->read();
        $offset = $state['telegram_update_offset'] ?? null;

        return is_numeric($offset) ? (int) $offset : null;
    }

    private function writeOffset(?int $offset): void
    {
        $state = $this->state->read();
        $state['telegram_update_offset'] = $offset;
        $this->state->write($state);
    }

    private function pollTimeoutSeconds(): int
    {
        $timeout = (int) $this->config->require('telegram', 'poll_timeout');

        return max(0, min(50, $timeout));
    }

    private function pollLimit(): int
    {
        $limit = (int) $this->config->require('telegram', 'poll_limit');

        return max(1, min(100, $limit));
    }

    private function retryDelayMs(): int
    {
        return max(250, (int) $this->config->require('telegram', 'poll_retry_delay_ms'));
    }
}
