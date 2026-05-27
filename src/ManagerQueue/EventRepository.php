<?php

declare(strict_types=1);

namespace CodexRuntime\ManagerQueue;

use CodexRuntime\Config;
use CodexRuntime\FileQueue\FileQueueLayout;
use CodexRuntime\TimestampId;
use RuntimeException;

final class EventRepository
{
    private readonly FileQueueLayout $layout;

    public function __construct(private Config $config)
    {
        $this->layout = new FileQueueLayout($config);
    }

    public function enqueue(array $event): string
    {
        $id = $event['id'] ?? TimestampId::next();
        $event['id'] = $id;
        $event['created_at'] = $event['created_at'] ?? date(DATE_ATOM);
        $event['priority'] = (int) ($event['priority'] ?? 50);

        $path = $this->queuePath('new', $id);
        $this->writeJson($path, $event);

        return $id;
    }

    public function mergePendingUserMessage(string $runtimeId, string $text): ?string
    {
        return $this->mergePendingRuntimeMessage($runtimeId, $text);
    }

    public function mergePendingRuntimeMessage(string $runtimeId, string $text): ?string
    {
        $runtimeId = trim($runtimeId);
        if ($runtimeId === '') {
            return null;
        }

        $dir = $this->layout->queueDir('manager', 'new');
        $files = glob($dir . '/*.json');
        if ($files === false || $files === []) {
            return null;
        }

        $candidatePath = null;
        $candidateCreatedAt = '';
        foreach ($files as $path) {
            $event = $this->loadEvent($path);
            if (($event['type'] ?? '') !== 'user_message') {
                continue;
            }

            $eventRuntimeId = trim((string) ($event['session_id'] ?? ''));
            if ($eventRuntimeId !== $runtimeId) {
                continue;
            }

            $createdAt = (string) ($event['created_at'] ?? '');
            if ($candidatePath === null || strcmp($createdAt, $candidateCreatedAt) > 0) {
                $candidatePath = $path;
                $candidateCreatedAt = $createdAt;
            }
        }

        if ($candidatePath === null) {
            return null;
        }

        $event = $this->loadEvent($candidatePath);
        $existingText = trim((string) ($event['text'] ?? ''));
        $incomingText = trim($text);
        $event['text'] = trim($existingText . "\n\n" . $incomingText);
        $event['updated_at'] = date(DATE_ATOM);
        $this->writeJson($candidatePath, $event);

        return (string) ($event['id'] ?? basename($candidatePath, '.json'));
    }

    public function nextPendingPath(): ?string
    {
        $dir = $this->layout->queueDir('manager', 'new');
        $files = glob($dir . '/*.json');
        if ($files === false || $files === []) {
            return null;
        }

        usort($files, function (string $left, string $right): int {
            $leftEvent = $this->loadEvent($left);
            $rightEvent = $this->loadEvent($right);

            $leftPriority = (int) ($leftEvent['priority'] ?? 50);
            $rightPriority = (int) ($rightEvent['priority'] ?? 50);
            if ($leftPriority !== $rightPriority) {
                return $rightPriority <=> $leftPriority;
            }

            return strcmp((string) ($leftEvent['created_at'] ?? ''), (string) ($rightEvent['created_at'] ?? ''));
        });

        return $files[0];
    }

    public function loadEvent(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read event {$path}");
        }

        $event = json_decode($raw, true);
        if (!is_array($event)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        return $event;
    }

    public function moveToRunning(string $path): string
    {
        $id = basename($path, '.json');
        $target = $this->queuePath('running', $id);
        if (!rename($path, $target)) {
            throw new RuntimeException("Cannot move {$id} to running");
        }

        return $target;
    }

    /**
     * @return string[]
     */
    public function requeueAllRunning(): array
    {
        $dir = $this->layout->queueDir('manager', 'running');
        $files = glob($dir . '/*.json');
        if ($files === false || $files === []) {
            return [];
        }

        $requeued = [];
        foreach ($files as $path) {
            $event = $this->loadEvent($path);
            $id = (string) ($event['id'] ?? basename($path, '.json'));
            $event['requeued_at'] = date(DATE_ATOM);
            $target = $this->queuePath('new', $id);
            $this->writeJson($target, $event);
            unlink($path);
            $requeued[] = $id;
        }

        return $requeued;
    }

    public function finish(string $runningPath, string $status, array $result): void
    {
        $id = basename($runningPath, '.json');
        $resultsDir = $this->layout->resultsDir('manager');

        $result['id'] = $id;
        $result['finished_at'] = date(DATE_ATOM);
        $this->writeJson($resultsDir . '/' . $id . '.result.json', $result);
        file_put_contents($resultsDir . '/' . $id . '.stdout.log', (string) ($result['stdout'] ?? ''));
        file_put_contents($resultsDir . '/' . $id . '.stderr.log', (string) ($result['stderr'] ?? ''));

        $target = $this->queuePath($status === 'done' ? 'done' : 'failed', $id);
        if (!rename($runningPath, $target)) {
            throw new RuntimeException("Cannot move {$id} to {$status}");
        }
    }

    private function queuePath(string $queue, string $id): string
    {
        return $this->layout->queuePath('manager', $queue, $id);
    }

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

        $tmpPath = $dir . '/.' . basename($path) . '.tmp-' . substr(bin2hex(random_bytes(4)), 0, 8);
        if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write JSON temp file for {$path}");
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException("Cannot atomically publish JSON for {$path}");
        }
    }
}
