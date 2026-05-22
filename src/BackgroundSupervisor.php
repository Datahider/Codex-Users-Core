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
        if (!(bool) $this->config->get('background', 'enabled', true)) {
            return;
        }

        $this->ensureWorker(
            'manager_worker',
            (string) $this->config->require('background', 'manager_worker_pid_file'),
            $this->workerBootstrapCode('manager_worker'),
            (string) $this->config->require('manager_queue', 'lock_file')
        );

        $this->ensureWorker(
            'exec_watcher',
            (string) $this->config->require('background', 'exec_watcher_pid_file'),
            $this->workerBootstrapCode('exec_watcher'),
            (string) $this->config->require('exec_watcher', 'lock_file')
        );

        $this->ensureWorker(
            'command_watcher',
            (string) $this->config->require('background', 'command_watcher_pid_file'),
            $this->workerBootstrapCode('command_watcher'),
            (string) $this->config->require('command_watcher', 'lock_file')
        );

        $this->ensureWorker(
            'control_watcher',
            (string) $this->config->require('background', 'control_watcher_pid_file'),
            $this->workerBootstrapCode('control_watcher'),
            (string) $this->config->require('control_queue', 'lock_file')
        );

        $this->ensureWorker(
            'scheduler_worker',
            (string) $this->config->require('background', 'scheduler_worker_pid_file'),
            $this->workerBootstrapCode('scheduler_worker'),
            (string) $this->config->require('scheduled_queue', 'lock_file')
        );

        if ((bool) $this->config->get('idle_watchdog', 'enabled', true)) {
            $this->ensureWorker(
                'idle_watchdog',
                (string) $this->config->get('background', 'idle_watchdog_pid_file', __DIR__ . '/../var/run/idle-watchdog.pid'),
                $this->workerBootstrapCode('idle_watchdog'),
                (string) $this->config->get('idle_watchdog', 'lock_file', __DIR__ . '/../var/run/idle-watchdog.lock')
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
use CodexRuntime\OutboundQueue\MessageRepository;
use CodexRuntime\QueueTransportClient;
use CodexRuntime\QueueStatusMessageService;
use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\CoreEventSource;
use CodexRuntime\Router\CurlHttpClient;
use CodexRuntime\Router\TransportIngressGateway;
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$logger = new Logger((string) $config->require('storage', 'log_file'));
$events = new EventRepository($config);
$stateStore = new JsonFileStore((string) $config->require('storage', 'manager_state_file'));
$transport = new QueueTransportClient(new MessageRepository($config));
$statusMessages = new QueueStatusMessageService($config, new MessageRepository($config));
$shutdown = new WorkerShutdownFlag($config, 'background', 'manager_worker_shutdown_flag_file');
$activeTurn = new ActiveTurnRegistry(dirname((string) $config->require('storage', 'manager_state_file')) . '/active-turn.json');
$codex = new CodexProcess($config, $logger, $activeTurn);
$routerApi = new ApiClient(
    (string) $config->require('router', 'base_url'),
    (string) $config->require('router', 'core_token'),
    new CurlHttpClient()
);
$worker = new ManagerWorker($config, $logger, $events, $stateStore, $statusMessages, $shutdown, $transport, $codex, new CoreEventSource($routerApi));
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
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$logger = new Logger((string) $config->require('storage', 'log_file'));
$jobs = new JobRepository($config, 'command');
$runner = new CommandRunner($config, 'command_watcher');
$projects = new ProjectsRegistry($config);
$shutdown = new WorkerShutdownFlag($config, 'background', 'command_watcher_shutdown_flag_file');
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
use CodexRuntime\OutboundQueue\MessageRepository;
use CodexRuntime\QueueTransportClient;
use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\CurlHttpClient;
use CodexRuntime\Router\TransportIngressGateway;
use CodexRuntime\TransportMessageIngress;
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$logger = new Logger((string) $config->require('storage', 'log_file'));
$commands = new CommandRepository($config);
$activeTurn = new ActiveTurnRegistry(dirname((string) $config->require('storage', 'manager_state_file')) . '/active-turn.json');
$stateStore = new JsonFileStore((string) $config->require('storage', 'manager_state_file'));
$transport = new QueueTransportClient(new MessageRepository($config));
$routerApi = new ApiClient(
    (string) $config->require('router', 'base_url'),
    (string) $config->require('router', 'transport_token'),
    new CurlHttpClient()
);
$ingress = new TransportMessageIngress(new TransportIngressGateway($routerApi));
$sessions = new CodexSessionCatalog();
$shutdown = new WorkerShutdownFlag($config, 'background', 'control_watcher_shutdown_flag_file');
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
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$logger = new Logger((string) $config->require('storage', 'log_file'));
$jobs = new JobRepository($config, 'exec');
$runner = new CommandRunner($config, 'exec_watcher');
$projects = new ProjectsRegistry($config);
$shutdown = new WorkerShutdownFlag($config, 'background', 'exec_watcher_shutdown_flag_file');
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
use CodexRuntime\ScheduledQueue\ScheduledJobRepository;
use CodexRuntime\SchedulerWorker;
use CodexRuntime\WorkerShutdownFlag;
$config = Config::fromFile(%s);
$logger = new Logger((string) $config->require('storage', 'log_file'));
$scheduledJobs = new ScheduledJobRepository($config);
$commandJobs = new JobRepository($config, 'command');
$managerEvents = new EventRepository($config);
$shutdown = new WorkerShutdownFlag($config, 'background', 'scheduler_worker_shutdown_flag_file');
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
