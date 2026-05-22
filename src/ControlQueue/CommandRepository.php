<?php

declare(strict_types=1);

namespace CodexRuntime\ControlQueue;

use CodexRuntime\Config;
use CodexRuntime\FileQueue\FileQueueLayout;
use RuntimeException;

final class CommandRepository
{
    private readonly FileQueueLayout $layout;

    public function __construct(
        private Config $config
    ) {
        $this->layout = new FileQueueLayout($config);
    }

    /**
     * @param array<string, mixed> $command
     */
    public function enqueue(array $command): string
    {
        $id = $command['id'] ?? (date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8));
        $command['id'] = $id;
        $command['created_at'] = $command['created_at'] ?? date(DATE_ATOM);

        $path = $this->queuePath('new', $id);
        $this->writeJson($path, $command);

        return $id;
    }

    public function nextPendingPath(): ?string
    {
        $dir = $this->layout->queueDir('control', 'new');
        $files = glob($dir . '/*.json');
        if ($files === false || $files === []) {
            return null;
        }

        sort($files, SORT_STRING);

        return $files[0];
    }

    /**
     * @return array<string, mixed>
     */
    public function loadCommand(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read command {$path}");
        }

        $command = json_decode($raw, true);
        if (!is_array($command)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        return $command;
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
        $dir = $this->layout->queueDir('control', 'running');
        $files = glob($dir . '/*.json');
        if ($files === false || $files === []) {
            return [];
        }

        $requeued = [];
        foreach ($files as $path) {
            $command = $this->loadCommand($path);
            $id = (string) ($command['id'] ?? basename($path, '.json'));
            $command['requeued_at'] = date(DATE_ATOM);
            $target = $this->queuePath('new', $id);
            $this->writeJson($target, $command);
            @unlink($path);
            $requeued[] = $id;
        }

        return $requeued;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function finish(string $runningPath, string $status, array $result): void
    {
        $id = basename($runningPath, '.json');
        $resultsDir = $this->layout->resultsDir('control');

        $result['id'] = $id;
        $result['finished_at'] = date(DATE_ATOM);
        $this->writeJson($resultsDir . '/' . $id . '.result.json', $result);

        $target = $this->queuePath($status === 'done' ? 'done' : 'failed', $id);
        if (!rename($runningPath, $target)) {
            throw new RuntimeException("Cannot move {$id} to {$status}");
        }
    }

    private function queuePath(string $queue, string $id): string
    {
        return $this->layout->queuePath('control', $queue, $id);
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

        if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write {$path}");
        }
    }
}
