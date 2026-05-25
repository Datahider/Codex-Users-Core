<?php

declare(strict_types=1);

namespace CodexRuntime\OutboundQueue;

use CodexRuntime\Config;
use CodexRuntime\FileQueue\FileQueueLayout;
use RuntimeException;

final class MessageRepository
{
    private readonly FileQueueLayout $layout;

    public function __construct(private Config $config)
    {
        $this->layout = new FileQueueLayout($config);
    }

    /**
     * @param array<string, mixed> $message
     */
    public function enqueue(array $message): string
    {
        $id = $message['id'] ?? $this->nextMessageId();
        $message['id'] = $id;
        $message['created_at'] = $message['created_at'] ?? date(DATE_ATOM);

        $path = $this->queuePath('new', (string) $id);
        $this->writeJson($path, $message);

        return (string) $id;
    }

    /**
     * @return string[]
     */
    public function listPendingPaths(): array
    {
        $dir = $this->layout->queueDir('outbound', 'new');
        $files = glob($dir . '/*.json');

        if ($files === false) {
            return [];
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadMessage(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read outbound message {$path}");
        }

        $message = json_decode($raw, true);
        if (!is_array($message)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        return $message;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadMessageIfPresent(string $path): ?array
    {
        try {
            return $this->loadMessage($path);
        } catch (RuntimeException $e) {
            if (!is_file($path)) {
                return null;
            }

            throw $e;
        }
    }

    public function markDone(string $path): void
    {
        $id = basename($path, '.json');
        $target = $this->queuePath('done', $id);
        if (!is_file($path)) {
            if (is_file($target)) {
                return;
            }

            throw new RuntimeException("Outbound message {$id} is already missing from queue");
        }

        if (!rename($path, $target)) {
            throw new RuntimeException("Cannot move outbound message {$id} to done");
        }
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $errorContext
     */
    public function markFailed(string $path, array $message, string $reason, array $errorContext = []): void
    {
        $id = basename($path, '.json');
        $target = $this->queuePath('failed', $id);

        if (!is_file($path)) {
            if (is_file($target)) {
                return;
            }

            throw new RuntimeException("Outbound message {$id} is already missing from queue");
        }

        $failed = $message;
        $failed['failed_at'] = date(DATE_ATOM);
        $failed['failure_reason'] = $reason;
        if ($errorContext !== []) {
            $failed['failure_context'] = $errorContext;
        }

        $this->writeJson($target, $failed);

        if (!unlink($path)) {
            throw new RuntimeException("Cannot remove failed outbound message {$id} from new queue");
        }
    }

    private function queuePath(string $queue, string $id): string
    {
        return $this->layout->queuePath('outbound', $queue, $id);
    }

    private function nextMessageId(): string
    {
        $now = microtime(true);
        $seconds = (int) $now;
        $micros = (int) round(($now - $seconds) * 1000000);
        if ($micros >= 1000000) {
            $seconds += 1;
            $micros = 0;
        }

        return date('Ymd-His', $seconds) . '-' . str_pad((string) $micros, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("Cannot encode JSON for {$path}");
        }

        file_put_contents($path, $json . PHP_EOL, LOCK_EX);
    }
}
