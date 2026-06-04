<?php

declare(strict_types=1);

namespace CodexRuntime\CommandWatcher;

use CodexRuntime\Config;
use CodexRuntime\Environment;
use RuntimeException;

final class CommandRunner
{
    public function __construct(
        private Config $config,
        private string $section = 'command_watcher'
    )
    {
    }

    public function run(array $job): array
    {
        $command = trim((string) ($job['command'] ?? ''));
        if ($command === '') {
            throw new RuntimeException('Job command is empty');
        }

        $this->assertAllowed($command);

        $cwd = $this->resolveWorkingDirectory($job);
        $timeout = (int) ($job['timeout'] ?? $this->config->get($this->section, 'default_timeout', 600));
        $env = is_array($job['env'] ?? null) ? $job['env'] : [];
        $wrappedCommand = $this->wrapCommand($command);

        $this->assertWorkdirAllowed($cwd);

        $process = proc_open(
            ['/bin/bash', '-lc', $wrappedCommand],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd,
            $env
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start process');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $startedAt = microtime(true);

        while (true) {
            $status = proc_get_status($process);

            $stdoutChunk = stream_get_contents($pipes[1]);
            if ($stdoutChunk !== false && $stdoutChunk !== '') {
                $stdout .= $stdoutChunk;
            }

            $stderrChunk = stream_get_contents($pipes[2]);
            if ($stderrChunk !== false && $stderrChunk !== '') {
                $stderr .= $stderrChunk;
            }

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $startedAt) > $timeout) {
                proc_terminate($process, 15);
                $timedOut = true;
                break;
            }

            usleep(200000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        $this->safeClose($pipes[1] ?? null);
        $this->safeClose($pipes[2] ?? null);
        $exitCode = proc_close($process);

        if ($timedOut && $exitCode === 0) {
            $exitCode = 124;
        }

        return [
            'ok' => !$timedOut && $exitCode === 0,
            'timed_out' => $timedOut,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'command' => $command,
            'cwd' => $cwd,
            'timeout' => $timeout,
        ];
    }

    private function wrapCommand(string $command): string
    {
        return <<<'BASH'
for fd_path in /proc/$$/fd/*; do
  fd="${fd_path##*/}"
  case "$fd" in
    0|1|2)
      continue
      ;;
  esac
  eval "exec ${fd}<&-" 2>/dev/null || true
  eval "exec ${fd}>&-" 2>/dev/null || true
done
BASH . "\n" . $command;
    }

    private function assertAllowed(string $command): void
    {
        foreach ((array) $this->config->get($this->section, 'deny_patterns', []) as $pattern) {
            if (@preg_match((string) $pattern, $command) === 1) {
                throw new RuntimeException("Command rejected by deny rule: {$pattern}");
            }
        }
    }

    private function resolveWorkingDirectory(array $job): string
    {
        $cwd = trim((string) ($job['cwd'] ?? $this->config->get($this->section, 'default_cwd', $this->defaultHomeDirectory())));
        if ($cwd === '') {
            throw new RuntimeException('Working directory is empty');
        }

        return $cwd;
    }

    private function assertWorkdirAllowed(string $cwd): void
    {
        foreach ($this->allowedWorkdirs() as $root) {
            $root = rtrim((string) $root, '/');
            if ($cwd === $root || str_starts_with($cwd, $root . '/')) {
                return;
            }
        }

        throw new RuntimeException("Working directory is not allowed: {$cwd}");
    }

    /**
     * @return list<string>
     */
    private function allowedWorkdirs(): array
    {
        $roots = (array) $this->config->get($this->section, 'allowed_workdirs', [$this->defaultHomeDirectory()]);
        $normalized = [];
        foreach ($roots as $root) {
            $path = trim((string) $root);
            if ($path !== '') {
                $normalized[] = $path;
            }
        }

        if ($normalized === []) {
            throw new RuntimeException('Allowed working directories list is empty');
        }

        return $normalized;
    }

    private function defaultHomeDirectory(): string
    {
        $home = Environment::homeDirectory();
        if ($home === null || $home === '') {
            throw new RuntimeException('Cannot resolve home directory for default working directory');
        }

        return $home;
    }

    private function safeClose(mixed $resource): void
    {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }
}
