<?php

declare(strict_types=1);

namespace CodexRuntime\Max;

use CodexRuntime\Config;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\WorkerShutdownFlag;
use MaxApi\BotApi;
use Throwable;

final class MaxLongPollingRunner
{
    public function __construct(
        private Config $config,
        private Logger $logger,
        private BotApi $api,
        private MaxUpdateIngress $ingress,
        private JsonFileStore $state,
        private ?WorkerShutdownFlag $shutdown = null
    ) {
    }

    public function run(): void
    {
        $timeout = $this->longPollTimeoutSeconds();
        $retryDelayMs = $this->retryDelayMs();
        $marker = $this->readMarker();

        $this->logger->info('Starting MAX long polling', [
            'timeout_seconds' => $timeout,
            'limit' => $this->longPollLimit(),
            'marker' => $marker,
        ]);

        while (true) {
            if ($this->shutdown?->consumeIfRequested()) {
                $this->logger->info('MAX long polling exiting for shutdown request');
                return;
            }

            try {
                $marker = $this->processBatch($marker);
            } catch (Throwable $e) {
                $this->logger->error('MAX long polling batch failed', [
                    'message' => $e->getMessage(),
                    'marker' => $marker,
                ]);
                usleep($retryDelayMs * 1000);
            }
        }
    }

    public function processBatch(?int $marker): ?int
    {
        $updateList = $this->api->subscriptions->getUpdates(
            limit: $this->longPollLimit(),
            timeout: $this->longPollTimeoutSeconds(),
            marker: $marker
        );

        foreach ($updateList->items() as $update) {
            $payload = $this->normalizeUpdatePayload($update);
            $this->ingress->ingest($payload);
        }

        $nextMarker = $updateList->marker;
        $this->writeMarker($nextMarker);

        return $nextMarker;
    }

    private function readMarker(): ?int
    {
        $state = $this->state->read();
        $marker = $state['max_long_poll_marker'] ?? null;

        return is_int($marker) ? $marker : (is_numeric($marker) ? (int) $marker : null);
    }

    private function writeMarker(?int $marker): void
    {
        $state = $this->state->read();
        $state['max_long_poll_marker'] = $marker;
        $this->state->write($state);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeUpdatePayload(object $update): array
    {
        if (method_exists($update, 'toArray')) {
            $payload = $update->toArray();
            if (is_array($payload)) {
                return $payload;
            }
        }

        return get_object_vars($update);
    }

    private function longPollTimeoutSeconds(): int
    {
        $timeout = (int) $this->config->require('max', 'long_poll_timeout_seconds');

        return max(0, min(90, $timeout));
    }

    private function longPollLimit(): int
    {
        $limit = (int) $this->config->require('max', 'long_poll_limit');

        return max(1, min(1000, $limit));
    }

    private function retryDelayMs(): int
    {
        return max(250, (int) $this->config->require('max', 'long_poll_retry_delay_ms'));
    }
}
