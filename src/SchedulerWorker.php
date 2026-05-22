<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\CommandWatcher\JobRepository;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\ScheduledQueue\ScheduledJobRepository;
use RuntimeException;
use Throwable;

final class SchedulerWorker
{
    private $lockHandle = null;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private ScheduledJobRepository $scheduledJobs,
        private JobRepository $commandJobs,
        private EventRepository $managerEvents,
        private WorkerShutdownFlag $shutdown
    ) {
    }

    public function run(): void
    {
        $this->acquireLock();
        $this->logger->info('Scheduler worker started');
        $pollIntervalMs = (int) $this->config->get('scheduled_queue', 'poll_interval_ms', 1000);

        while (true) {
            if ($this->shutdown->consumeIfRequested()) {
                $this->logger->info('Scheduler worker exiting for shutdown request');
                return;
            }

            $duePaths = $this->scheduledJobs->duePaths(date('Ymd-His'));
            if ($duePaths === []) {
                usleep($pollIntervalMs * 1000);
                continue;
            }

            foreach ($duePaths as $path) {
                if ($this->shutdown->consumeIfRequested()) {
                    $this->logger->info('Scheduler worker exiting for shutdown request');
                    return;
                }

                try {
                    $this->releaseScheduledJob($path);
                } catch (Throwable $e) {
                    $this->logger->error('Scheduler worker error', [
                        'scheduled_path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function releaseScheduledJob(string $path): void
    {
        $scheduledJob = $this->scheduledJobs->load($path);
        $scheduledId = (string) ($scheduledJob['id'] ?? basename($path, '.json'));
        $targetQueue = trim((string) ($scheduledJob['target_queue'] ?? ''));
        $payload = $scheduledJob['payload'] ?? null;

        if (!is_array($payload)) {
            throw new RuntimeException('Scheduled job payload must be an object');
        }

        if (!in_array($targetQueue, ['command', 'manager'], true)) {
            throw new RuntimeException("Unsupported scheduled target_queue {$targetQueue}");
        }

        $targetId = trim((string) ($payload['id'] ?? $scheduledId));
        if ($targetId === '') {
            throw new RuntimeException('Scheduled target id cannot be empty');
        }

        if ($this->scheduledJobs->targetExists($targetQueue, $targetId)) {
            $this->scheduledJobs->delete($path);
            $this->logger->info('Scheduled job already released; removed source', [
                'scheduled_id' => $scheduledId,
                'target_queue' => $targetQueue,
                'target_id' => $targetId,
            ]);

            return;
        }

        $payload['id'] = $targetId;
        if ($targetQueue === 'command') {
            $payload['scheduled_from'] = $scheduledId;
            $payload['scheduled_at'] = (string) ($scheduledJob['scheduled_at'] ?? '');
            $this->commandJobs->enqueue($payload);
        } else {
            $payload['meta'] ??= [];
            if (!is_array($payload['meta'])) {
                $payload['meta'] = [];
            }
            $payload['meta']['scheduled_from'] = $scheduledId;
            $payload['meta']['scheduled_at'] = (string) ($scheduledJob['scheduled_at'] ?? '');
            $payload['meta']['scheduled_created_at'] = (string) ($scheduledJob['created_at'] ?? '');
            $this->managerEvents->enqueue($payload);
        }

        $this->scheduledJobs->delete($path);
        $this->logger->info('Released scheduled job', [
            'scheduled_id' => $scheduledId,
            'target_queue' => $targetQueue,
            'target_id' => $targetId,
        ]);
    }

    private function acquireLock(): void
    {
        $lockFile = (string) $this->config->require('scheduled_queue', 'lock_file');
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($lockFile, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException("Cannot open {$lockFile}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Scheduler worker is already running');
        }

        $this->lockHandle = $handle;
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        $pidFile = (string) $this->config->require('background', 'scheduler_worker_pid_file');
        file_put_contents($pidFile, (string) getmypid());
    }
}
