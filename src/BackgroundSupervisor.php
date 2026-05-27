<?php

declare(strict_types=1);

namespace CodexRuntime;

use losthost\BackgroundProcess\BackgroundProcess;
use RuntimeException;

final class BackgroundSupervisor
{
    public function __construct(
        private Config $config,
        private Logger $logger,
        private string $configPath
    ) {
    }

    public function ensureStarted(): void
    {
        $paths = new RuntimePaths($this->config);
        if (!(bool) $this->config->get('background', 'enabled', true)) {
            return;
        }

        $this->ensureWorker(
            'router_ingress_worker',
            (string) $this->config->get('background', 'router_ingress_worker_pid_file', $paths->workerPidFile('router_ingress_worker')),
            $this->workerBootstrapCode('router_ingress_worker'),
            (string) $this->config->get('router', 'lock_file', $paths->workerLockFile('router_ingress_worker'))
        );

        $this->ensureWorker(
            'manager_worker',
            (string) $this->config->get('background', 'manager_worker_pid_file', $paths->workerPidFile('manager_worker')),
            $this->workerBootstrapCode('manager_worker'),
            (string) $this->config->get('manager_queue', 'lock_file', $paths->workerLockFile('manager_worker'))
        );

        $this->ensureWorker(
            'exec_watcher',
            (string) $this->config->get('background', 'exec_watcher_pid_file', $paths->workerPidFile('exec_watcher')),
            $this->workerBootstrapCode('exec_watcher'),
            (string) $this->config->get('exec_watcher', 'lock_file', $paths->workerLockFile('exec_watcher'))
        );

        $this->ensureWorker(
            'command_watcher',
            (string) $this->config->get('background', 'command_watcher_pid_file', $paths->workerPidFile('command_watcher')),
            $this->workerBootstrapCode('command_watcher'),
            (string) $this->config->get('command_watcher', 'lock_file', $paths->workerLockFile('command_watcher'))
        );

        $this->ensureWorker(
            'control_watcher',
            (string) $this->config->get('background', 'control_watcher_pid_file', $paths->workerPidFile('control_watcher')),
            $this->workerBootstrapCode('control_watcher'),
            (string) $this->config->get('control_queue', 'lock_file', $paths->workerLockFile('control_watcher'))
        );

        $this->ensureWorker(
            'scheduler_worker',
            (string) $this->config->get('background', 'scheduler_worker_pid_file', $paths->workerPidFile('scheduler_worker')),
            $this->workerBootstrapCode('scheduler_worker'),
            (string) $this->config->get('scheduled_queue', 'lock_file', $paths->workerLockFile('scheduler_worker'))
        );

        if ((bool) $this->config->get('idle_watchdog', 'enabled', false)) {
            $this->ensureWorker(
                'idle_watchdog',
                (string) $this->config->get('background', 'idle_watchdog_pid_file', $paths->workerPidFile('idle_watchdog')),
                $this->workerBootstrapCode('idle_watchdog'),
                (string) $this->config->get('idle_watchdog', 'lock_file', $paths->workerLockFile('idle_watchdog'))
            );
        }
    }

    private function ensureWorker(string $name, string $pidFile, string $bootstrapCode, ?string $lockFile = null): void
    {
        if ($this->isPidAlive($pidFile) || ($lockFile !== null && $this->isLockHeld($lockFile))) {
            return;
        }

        $configPath = realpath($this->configPath);
        $projectRoot = realpath(__DIR__ . '/..');
        $bootstrapPath = realpath(__DIR__ . '/bootstrap.php');
        if ($configPath === false || $projectRoot === false || $bootstrapPath === false) {
            throw new RuntimeException("Cannot resolve worker paths for {$name}");
        }

        $dir = dirname($pidFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $logDir = $projectRoot . '/var/log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/' . basename($pidFile, '.pid') . '.log';

        $process = BackgroundProcess::create($bootstrapCode)->run(
            $projectRoot,
            $bootstrapPath,
            $configPath,
            $logFile
        );
        $pid = $process->getPid();
        file_put_contents($pidFile, (string) $pid);
        $this->logger->info('Started background worker', [
            'worker' => $name,
            'pid' => $pid,
        ]);
    }

    private function workerBootstrapCode(string $worker): string
    {
        return match ($worker) {
            'manager_worker' => <<<'PHP'
<?php
chdir(%s);
require %s;
use CodexRuntime\ActiveTurnRegistry;
use CodexRuntime\Config;
use CodexRuntime\CodexProcess;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\ManagerWorker;
use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\CurlHttpClient;
use CodexRuntime\Router\RouterStatusMessageService;
use CodexRuntime\Router\RouterTransportClient;
use CodexRuntime\RuntimePaths;
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$paths = new RuntimePaths($config);
$logger = new Logger((string) $config->get('storage', 'log_file', $paths->logFile()));
$events = new EventRepository($config);
$stateStore = new JsonFileStore((string) $config->get('storage', 'manager_state_file', $paths->managerStateFile()));
$transport = new RouterTransportClient(new ApiClient(
    (string) $config->require('router', 'base_url'),
    (string) $config->require('router', 'core_token'),
    new CurlHttpClient()
));
$statusMessages = new RouterStatusMessageService($config, $transport);
$shutdown = new WorkerShutdownFlag($config, 'background', 'manager_worker_shutdown_flag_file', $paths->workerShutdownFlagFile('manager_worker'));
$activeTurn = new ActiveTurnRegistry($paths->activeTurnFile());
$codex = new CodexProcess($config, $logger, $activeTurn);
$worker = new ManagerWorker($config, $logger, $events, $stateStore, $statusMessages, $shutdown, $transport, $codex);
$worker->run();
PHP,
            'router_ingress_worker' => <<<'PHP'
<?php
chdir(%s);
require %s;
use CodexRuntime\Config;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\CoreEventSource;
use CodexRuntime\Router\CurlHttpClient;
use CodexRuntime\Router\RouterIngressWorker;
use CodexRuntime\RuntimePaths;
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$paths = new RuntimePaths($config);
$logger = new Logger((string) $config->get('storage', 'log_file', $paths->logFile()));
$source = new CoreEventSource(new ApiClient(
    (string) $config->require('router', 'base_url'),
    (string) $config->require('router', 'core_token'),
    new CurlHttpClient()
));
$events = new EventRepository($config);
$stateStore = new JsonFileStore((string) $config->get('router', 'state_file', $paths->routerStateFile()));
$shutdown = new WorkerShutdownFlag($config, 'background', 'router_ingress_worker_shutdown_flag_file', $paths->workerShutdownFlagFile('router_ingress_worker'));
$worker = new RouterIngressWorker($config, $logger, $source, $events, $stateStore, $shutdown);
$worker->run();
PHP,
            'command_watcher' => <<<'PHP'
<?php
chdir(%s);
require %s;
use CodexRuntime\CommandWatcher\CommandRunner;
use CodexRuntime\CommandWatcher\JobRepository;
use CodexRuntime\CommandWatcher\Watcher;
use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\ProjectsRegistry;
use CodexRuntime\RuntimePaths;
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$paths = new RuntimePaths($config);
$logger = new Logger((string) $config->get('storage', 'log_file', $paths->logFile()));
$jobs = new JobRepository($config, 'command');
$runner = new CommandRunner($config, 'command_watcher');
$projects = new ProjectsRegistry($config);
$shutdown = new WorkerShutdownFlag($config, 'background', 'command_watcher_shutdown_flag_file', $paths->workerShutdownFlagFile('command_watcher'));
$managerEvents = new EventRepository($config);
$watcher = new Watcher($config, $logger, $jobs, $runner, $projects, $shutdown, $managerEvents, 'command_watcher', 'command watcher');
$watcher->run();
PHP,
            'control_watcher' => <<<'PHP'
<?php
chdir(%s);
require %s;
use CodexRuntime\ActiveTurnRegistry;
use CodexRuntime\CodexSessionCatalog;
use CodexRuntime\Config;
use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\ControlWatcher;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\CurlHttpClient;
use CodexRuntime\Router\RouterTransportClient;
use CodexRuntime\RuntimePaths;
use CodexRuntime\TransportMessageIngress;
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$paths = new RuntimePaths($config);
$logger = new Logger((string) $config->get('storage', 'log_file', $paths->logFile()));
$commands = new CommandRepository($config);
$activeTurn = new ActiveTurnRegistry($paths->activeTurnFile());
$stateStore = new JsonFileStore((string) $config->get('storage', 'manager_state_file', $paths->managerStateFile()));
$transport = new RouterTransportClient(new ApiClient(
    (string) $config->require('router', 'base_url'),
    (string) $config->require('router', 'core_token'),
    new CurlHttpClient()
));
$ingress = new TransportMessageIngress(new EventRepository($config));
$sessions = new CodexSessionCatalog();
$shutdown = new WorkerShutdownFlag($config, 'background', 'control_watcher_shutdown_flag_file', $paths->workerShutdownFlagFile('control_watcher'));
$watcher = new ControlWatcher($config, $logger, $commands, $activeTurn, $stateStore, $transport, $ingress, $sessions, $shutdown);
$watcher->run();
PHP,
            'exec_watcher' => <<<'PHP'
<?php
chdir(%s);
require %s;
use CodexRuntime\CommandWatcher\CommandRunner;
use CodexRuntime\CommandWatcher\JobRepository;
use CodexRuntime\CommandWatcher\Watcher;
use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\ProjectsRegistry;
use CodexRuntime\RuntimePaths;
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$paths = new RuntimePaths($config);
$logger = new Logger((string) $config->get('storage', 'log_file', $paths->logFile()));
$jobs = new JobRepository($config, 'exec');
$runner = new CommandRunner($config, 'exec_watcher');
$projects = new ProjectsRegistry($config);
$shutdown = new WorkerShutdownFlag($config, 'background', 'exec_watcher_shutdown_flag_file', $paths->workerShutdownFlagFile('exec_watcher'));
$watcher = new Watcher($config, $logger, $jobs, $runner, $projects, $shutdown, null, 'exec_watcher', 'exec watcher');
$watcher->run();
PHP,
            'scheduler_worker' => <<<'PHP'
<?php
chdir(%s);
require %s;
use CodexRuntime\CommandWatcher\JobRepository;
use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\RuntimePaths;
use CodexRuntime\ScheduledQueue\ScheduledJobRepository;
use CodexRuntime\SchedulerWorker;
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$paths = new RuntimePaths($config);
$logger = new Logger((string) $config->get('storage', 'log_file', $paths->logFile()));
$scheduledJobs = new ScheduledJobRepository($config);
$commandJobs = new JobRepository($config, 'command');
$managerEvents = new EventRepository($config);
$shutdown = new WorkerShutdownFlag($config, 'background', 'scheduler_worker_shutdown_flag_file', $paths->workerShutdownFlagFile('scheduler_worker'));
$worker = new SchedulerWorker($config, $logger, $scheduledJobs, $commandJobs, $managerEvents, $shutdown);
$worker->run();
PHP,
            'idle_watchdog' => <<<'PHP'
<?php
chdir(%s);
require %s;
use CodexRuntime\Config;
use CodexRuntime\IdleWatchdogWorker;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\ProjectsRegistry;
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$logger = new Logger((string) $config->require('storage', 'log_file'));
$managerEvents = new EventRepository($config);
$managerState = new JsonFileStore((string) $config->require('storage', 'manager_state_file'));
$state = new JsonFileStore((string) $config->get('idle_watchdog', 'state_file', __DIR__ . '/../var/state/idle-watchdog-state.json'));
$projects = new ProjectsRegistry($config);
$shutdown = new WorkerShutdownFlag($config, 'background', 'idle_watchdog_shutdown_flag_file');
$worker = new IdleWatchdogWorker($config, $logger, $managerEvents, $managerState, $state, $projects, $shutdown);
$worker->run();
PHP,
            default => throw new RuntimeException("Unknown worker {$worker}"),
        };
    }

    private function isPidAlive(string $pidFile): bool
    {
        if (!is_file($pidFile)) {
            return false;
        }

        $pid = (int) trim((string) file_get_contents($pidFile));
        if ($pid <= 0) {
            return false;
        }

        if ($this->isPidZombie($pid)) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $output = [];
        $code = 1;
        exec('ps -p ' . (int) $pid, $output, $code);

        return $code === 0 && count($output) > 1;
    }

    private function isPidZombie(int $pid): bool
    {
        $output = [];
        $code = 1;
        exec('ps -o stat= -p ' . $pid, $output, $code);
        if ($code !== 0 || $output === []) {
            return false;
        }

        $stat = strtoupper(trim((string) ($output[0] ?? '')));

        return $stat !== '' && $stat[0] === 'Z';
    }

    private function isLockHeld(string $lockFile): bool
    {
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            return false;
        }

        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            return false;
        }

        $lockHeld = !flock($handle, LOCK_EX | LOCK_NB);
        if (!$lockHeld) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $lockHeld;
    }
}
