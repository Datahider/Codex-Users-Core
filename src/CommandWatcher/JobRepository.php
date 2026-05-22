<?php

declare(strict_types=1);

namespace CodexRuntime\CommandWatcher;

use CodexRuntime\Config;
use CodexRuntime\FileQueue\FileQueueLayout;
use RuntimeException;

final class JobRepository
{
    private readonly FileQueueLayout $layout;

    public function __construct(
        private Config $config,
        private string $queueName = 'command'
    )
    {
        $this->layout = new FileQueueLayout($config);
    }

    public function enqueue(array $job): string
    {
        $project = trim((string) ($job['project'] ?? ''));
        if ($project === '') {
            throw new RuntimeException('Job project is required');
        }

        $id = $job['id'] ?? (date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8));
        $job['project'] = $project;
        $job['id'] = $id;
        $job['created_at'] = $job['created_at'] ?? date(DATE_ATOM);

        $path = $this->queuePath('new', $id);
        $this->writeJson($path, $job);

        return $id;
    }

    public function nextPendingPath(): ?string
    {
        $dir = $this->layout->queueDir($this->queueName, 'new');
        $files = glob($dir . '/*.json');
        if ($files === false || $files === []) {
            return null;
        }

        sort($files, SORT_STRING);

        return $files[0];
    }

    public function loadJob(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read job {$path}");
        }

        $job = json_decode($raw, true);
        if (!is_array($job)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        return $job;
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
        $dir = $this->layout->queueDir($this->queueName, 'running');
        $files = glob($dir . '/*.json');
        if ($files === false || $files === []) {
            return [];
        }

        $requeued = [];
        foreach ($files as $path) {
            $job = $this->loadJob($path);
            $id = (string) ($job['id'] ?? basename($path, '.json'));
            $job['requeued_at'] = date(DATE_ATOM);
            $target = $this->queuePath('new', $id);
            $this->writeJson($target, $job);
            unlink($path);
            $requeued[] = $id;
        }

        return $requeued;
    }

    public function finish(string $runningPath, string $status, array $result): void
    {
        $id = basename($runningPath, '.json');
        $resultsDir = $this->layout->resultsDir($this->queueName);

        $result['id'] = $id;
        $result['finished_at'] = date(DATE_ATOM);
        $this->writeJson($resultsDir . '/' . $id . '.result.json', $result);
        $this->writeFileAtomically($resultsDir . '/' . $id . '.stdout.log', (string) ($result['stdout'] ?? ''));
        $this->writeFileAtomically($resultsDir . '/' . $id . '.stderr.log', (string) ($result['stderr'] ?? ''));

        $target = $this->queuePath($status === 'done' ? 'done' : 'failed', $id);
        if (!rename($runningPath, $target)) {
            throw new RuntimeException("Cannot move {$id} to {$status}");
        }
    }

    private function queuePath(string $queue, string $id): string
    {
        return $this->layout->queuePath($this->queueName, $queue, $id);
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

        $this->writeFileAtomically($path, $json . PHP_EOL);
    }

    private function writeFileAtomically(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $tempPath = tempnam($dir, '.' . basename($path) . '.');
        if ($tempPath === false) {
            throw new RuntimeException("Cannot create temporary file for {$path}");
        }

        if (file_put_contents($tempPath, $contents, LOCK_EX) === false) {
            @unlink($tempPath);
            throw new RuntimeException("Cannot write temporary file for {$path}");
        }

        if (!rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new RuntimeException("Cannot publish {$path}");
        }
    }
}
