<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use CodexRuntime\Config;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\WorkerShutdownFlag;
use RuntimeException;

final class RouterIngressWorker
{
    private $lockHandle = null;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private CoreEventSourceInterface $source,
        private EventRepository $events,
        private JsonFileStore $stateStore,
        private WorkerShutdownFlag $shutdown
    ) {
    }

    public function run(): void
    {
        $this->acquireLock();
        $this->logger->info('Router ingress worker started');

        while (true) {
            if ($this->shutdown->consumeIfRequested()) {
                $this->logger->info('Router ingress worker exiting for shutdown request');
                return;
            }

            try {
                $processed = $this->pollOnce();
                if (!$processed) {
                    usleep(250 * 1000);
                }
            } catch (RouterUnavailableException $e) {
                $this->logger->error('Router ingress worker cannot reach router', ['error' => $e->getMessage()]);
                sleep(max(1, (int) $this->config->get('router', 'retry_unavailable_after_seconds', 15)));
            }
        }
    }

    public function pollOnce(): bool
    {
        $state = $this->stateStore->read();
        $afterId = (int) ($state['router_after_id'] ?? 0);
        $event = $this->source->pollNextEvent(
            $afterId,
            max(0, (int) $this->config->get('router', 'core_events_wait_seconds', 0)),
            max(1, (int) $this->config->get('router', 'core_events_limit', 1))
        );

        if (!is_array($event)) {
            return false;
        }

        $routerEventId = (int) ($event['router_event_id'] ?? 0);
        if ($routerEventId <= $afterId) {
            throw new RuntimeException('Router returned a non-advancing event id');
        }

        $runtimeSessionId = trim((string) ($event['session_id'] ?? ''));
        if ($runtimeSessionId === '') {
            throw new RuntimeException('Router event is missing runtime session id');
        }

        $text = trim((string) ($event['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('Router event is missing text payload');
        }

        $event['priority'] = max(50, (int) ($event['priority'] ?? 50));
        $event['session_id'] = $runtimeSessionId;
        $event['text'] = $text;
        $mergedEventId = $this->events->mergePendingRuntimeMessage($runtimeSessionId, $text);
        if ($mergedEventId === null) {
            $this->events->enqueue($event);
        } else {
            $event['id'] = $mergedEventId;
        }

        $state['router_after_id'] = $routerEventId;
        $this->stateStore->write($state);

        $this->logger->info('Accepted router core event', [
            'router_event_id' => $routerEventId,
            'runtime_session_id' => $runtimeSessionId,
            'event_id' => $event['id'] ?? null,
        ]);

        return true;
    }

    private function acquireLock(): void
    {
        $paths = new \CodexRuntime\RuntimePaths($this->config);
        $lockFile = (string) $this->config->get('router', 'lock_file', $paths->workerLockFile('router_ingress_worker'));
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($lockFile, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException("Cannot open {$lockFile}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Router ingress worker is already running');
        }

        $this->lockHandle = $handle;
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        $pidFile = (string) $this->config->get('background', 'router_ingress_worker_pid_file', $paths->workerPidFile('router_ingress_worker'));
        file_put_contents($pidFile, (string) getmypid());
    }
}
