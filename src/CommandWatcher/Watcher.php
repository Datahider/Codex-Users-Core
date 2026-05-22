<?php

declare(strict_types=1);

namespace CodexRuntime\CommandWatcher;

use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\ProjectsRegistry;
use CodexRuntime\WorkerShutdownFlag;
use RuntimeException;
use Throwable;

final class Watcher
{
    private $lockHandle = null;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private JobRepository $jobs,
        private CommandRunner $runner,
        private ProjectsRegistry $projects,
        private WorkerShutdownFlag $shutdown,
        private ?EventRepository $managerEvents = null,
        private string $section = 'command_watcher',
        private string $workerName = 'command watcher'
    ) {
    }

    public function run(): void
    {
        $this->acquireLock();
        $requeued = $this->jobs->requeueAllRunning();
        $this->logger->info(ucfirst($this->workerName) . ' started');
        if ($requeued !== []) {
            $this->logger->info('Requeued stale ' . $this->workerName . ' jobs', ['job_ids' => $requeued]);
        }

        $pollIntervalMs = (int) $this->config->get($this->section, 'poll_interval_ms', 1000);

        while (true) {
            if ($this->shutdown->consumeIfRequested()) {
                $this->logger->info(ucfirst($this->workerName) . ' exiting for shutdown request');
                return;
            }

            $runningPath = null;
            $job = null;
            try {
                $path = $this->jobs->nextPendingPath();
                if ($path === null) {
                    usleep($pollIntervalMs * 1000);
                    continue;
                }

                $runningPath = $this->jobs->moveToRunning($path);
                $job = $this->jobs->loadJob($runningPath);
                $jobId = (string) ($job['id'] ?? basename($runningPath, '.json'));
                $projectRoot = $this->projects->assertProjectExists((string) ($job['project'] ?? ''));

                $this->logger->info('Executing ' . $this->workerName . ' job', [
                    'job_id' => $jobId,
                    'project' => $projectRoot,
                    'command' => $job['command'] ?? '',
                    'cwd' => $job['cwd'] ?? null,
                ]);

                $this->projects->touch($projectRoot, [
                    'job_id' => $jobId,
                    'source' => $this->section,
                    'status' => 'started',
                ]);
                $result = $this->runner->run($job);
                $this->jobs->finish($runningPath, $result['ok'] ? 'done' : 'failed', $result);
                $this->bridgeResultToManagerIfNeeded($job, $result, $jobId);
                $this->projects->touch($projectRoot, [
                    'job_id' => $jobId,
                    'source' => $this->section,
                    'status' => $result['ok'] ? 'done' : 'failed',
                ]);

                $this->logger->info(ucfirst($this->workerName) . ' job finished', [
                    'job_id' => $jobId,
                    'project' => $projectRoot,
                    'ok' => $result['ok'],
                    'exit_code' => $result['exit_code'],
                ]);
            } catch (Throwable $e) {
                $this->logger->error(ucfirst($this->workerName) . ' error', ['error' => $e->getMessage()]);
                if ($runningPath !== null && is_file($runningPath)) {
                    try {
                        $failedJobId = is_array($job) ? (string) ($job['id'] ?? basename($runningPath, '.json')) : basename($runningPath, '.json');
                        $this->jobs->finish($runningPath, 'failed', [
                            'ok' => false,
                            'timed_out' => false,
                            'exit_code' => 1,
                            'stdout' => '',
                            'stderr' => $e->getMessage(),
                            'command' => is_array($job) ? (string) ($job['command'] ?? '') : '',
                            'cwd' => is_array($job) ? (string) ($job['cwd'] ?? '') : '',
                            'timeout' => is_array($job) ? (int) ($job['timeout'] ?? 0) : 0,
                            'project' => is_array($job) ? (string) ($job['project'] ?? '') : '',
                            'job_id' => $failedJobId,
                        ]);
                    } catch (Throwable $finishError) {
                        $this->logger->error(ucfirst($this->workerName) . ' failed to finalize errored job', [
                            'error' => $finishError->getMessage(),
                            'running_path' => $runningPath,
                        ]);
                    }
                }
                usleep($pollIntervalMs * 1000);
            }
        }
    }

    private function bridgeResultToManagerIfNeeded(array $job, array $result, string $jobId): void
    {
        if ($this->managerEvents === null) {
            return;
        }

        $meta = is_array($job['meta'] ?? null) ? $job['meta'] : [];
        if (empty($meta['bridge_to_manager'])) {
            return;
        }

        $runtimeSessionId = trim((string) ($meta['origin_runtime_session_id'] ?? ''));
        if ($runtimeSessionId === '') {
            $this->logger->error('Command watcher refused manager bridge without runtime session', [
                'job_id' => $jobId,
            ]);
            return;
        }

        $resultsDir = match ($this->section) {
            'exec_watcher' => (new \CodexRuntime\FileQueue\FileQueueLayout($this->config))->resultsDir('exec'),
            default => (new \CodexRuntime\FileQueue\FileQueueLayout($this->config))->resultsDir('command'),
        };
        $lastMessagePath = trim((string) ($meta['last_message_path'] ?? ''));

        $eventPayload = [
            'type' => 'background_result',
            'priority' => 45,
            'session_id' => $runtimeSessionId,
            'codex_session_id' => trim((string) ($meta['origin_codex_session_id'] ?? '')),
            'job_id' => $jobId,
            'command' => (string) ($result['command'] ?? $job['command'] ?? ''),
            'cwd' => (string) ($result['cwd'] ?? $job['cwd'] ?? ''),
            'timeout' => (int) ($result['timeout'] ?? $job['timeout'] ?? 0),
            'ok' => !empty($result['ok']),
            'timed_out' => !empty($result['timed_out']),
            'exit_code' => (int) ($result['exit_code'] ?? 1),
            'result_path' => $resultsDir . '/' . $jobId . '.result.json',
            'stdout_path' => $resultsDir . '/' . $jobId . '.stdout.log',
            'stderr_path' => $resultsDir . '/' . $jobId . '.stderr.log',
            'meta' => [
                'source' => 'background-command-result',
                'triggered_by_job' => $jobId,
            ],
        ];
        if ($lastMessagePath !== '') {
            $eventPayload['last_message_path'] = $lastMessagePath;
        }

        $eventId = $this->managerEvents->enqueue($eventPayload);

        $this->logger->info('Command watcher bridged finished job to manager queue', [
            'job_id' => $jobId,
            'event_id' => $eventId,
            'session_id' => $runtimeSessionId,
        ]);
    }

    private function acquireLock(): void
    {
        $lockFile = (string) $this->config->require($this->section, 'lock_file');
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($lockFile, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException("Cannot open {$lockFile}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            $existing = trim((string) file_get_contents($lockFile));
            $this->logger->error(ucfirst($this->workerName) . ' lock acquisition failed', [
                'lock_file' => $lockFile,
                'existing_lock_contents' => $existing,
                'pid' => getmypid(),
                'ppid' => function_exists('posix_getppid') ? posix_getppid() : null,
            ]);
            throw new RuntimeException(ucfirst($this->workerName) . ' already running');
        }

        $this->lockHandle = $handle;
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        $pidKey = $this->section === 'exec_watcher'
            ? 'exec_watcher_pid_file'
            : 'command_watcher_pid_file';
        $pidFile = (string) $this->config->require('background', $pidKey);
        file_put_contents($pidFile, (string) getmypid());
        $this->logger->info(ucfirst($this->workerName) . ' lock acquired', [
            'lock_file' => $lockFile,
            'pid_file' => $pidFile,
            'pid' => getmypid(),
            'ppid' => function_exists('posix_getppid') ? posix_getppid() : null,
        ]);
    }
}
