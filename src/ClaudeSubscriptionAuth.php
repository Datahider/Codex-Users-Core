<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class ClaudeSubscriptionAuth implements ClaudeSubscriptionAuthInterface
{
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];
    private string $output = '';
    private float $deadline = 0.0;

    public function __construct(private Config $config)
    {
    }

    public function __destruct()
    {
        $this->closePendingProcess();
    }

    public function isLoggedIn(): bool
    {
        $process = proc_open(
            [$this->claudeBin(), 'auth', 'status', '--json'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->workingDirectory(),
            $this->processEnvironment()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start Claude auth status');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
        $status = json_decode((string) $stdout, true);

        if (!is_array($status) || !is_bool($status['loggedIn'] ?? null)) {
            throw new RuntimeException('Claude auth status returned invalid JSON');
        }
        if (!in_array($exit_code, [0, 1], true)) {
            $details = trim((string) $stderr);
            throw new RuntimeException(
                'Claude auth status failed with exit code ' . $exit_code
                . ($details !== '' ? ': ' . $details : '')
            );
        }

        return $status['loggedIn'];
    }

    public function hasPendingLogin(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $this->closePendingProcess(false);
            return false;
        }

        return true;
    }

    public function beginLogin(callable $authorization): void
    {
        if ($this->hasPendingLogin()) {
            throw new RuntimeException('Claude OAuth flow is already pending');
        }

        $timeout_seconds = $this->timeoutSeconds();
        $process = proc_open(
            [$this->claudeBin(), 'auth', 'login', '--claudeai'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->workingDirectory(),
            $this->processEnvironment()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start Claude OAuth authorization');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        $this->output = '';
        $this->deadline = microtime(true) + $timeout_seconds;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        try {
            while (true) {
                $this->readOutput();
                $url = $this->authorizationUrl($this->output);
                if ($url !== null) {
                    $authorization($url);
                    return;
                }

                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    throw new RuntimeException('Claude OAuth authorization exited before returning a URL');
                }
                if (microtime(true) >= $this->deadline) {
                    throw new RuntimeException('Claude OAuth authorization timed out before returning a URL');
                }
                $this->waitForOutput();
            }
        } catch (\Throwable $e) {
            $this->closePendingProcess();
            throw $e;
        }
    }

    public function completeLogin(string $code): void
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('Claude OAuth code must not be empty');
        }
        if (!$this->hasPendingLogin()) {
            throw new RuntimeException('Claude OAuth flow is not pending');
        }
        if (microtime(true) >= $this->deadline) {
            $this->closePendingProcess();
            throw new RuntimeException('Claude OAuth authorization timed out');
        }

        $written = fwrite($this->pipes[0], $code . "\n");
        if ($written === false || $written !== strlen($code) + 1) {
            $this->closePendingProcess();
            throw new RuntimeException('Cannot send Claude OAuth code');
        }
        fclose($this->pipes[0]);
        unset($this->pipes[0]);

        $exit_code = -1;
        try {
            while (true) {
                $this->readOutput();
                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    $exit_code = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $this->deadline) {
                    throw new RuntimeException('Claude OAuth authorization timed out');
                }
                $this->waitForOutput();
            }
            $this->readOutput();
            if ($exit_code !== 0) {
                throw new RuntimeException(
                    'Claude OAuth authorization failed with exit code ' . $exit_code
                    . ($this->output !== '' ? ': ' . trim($this->stripAnsi($this->output)) : '')
                );
            }
        } finally {
            $this->closePendingProcess(false);
        }
    }

    private function readOutput(): void
    {
        foreach ([1, 2] as $index) {
            if (!isset($this->pipes[$index]) || !is_resource($this->pipes[$index])) {
                continue;
            }
            $chunk = stream_get_contents($this->pipes[$index]);
            if (is_string($chunk) && $chunk !== '') {
                $this->output .= $chunk;
            }
        }
        if (strlen($this->output) > 65536) {
            throw new RuntimeException('Claude OAuth authorization output is too large');
        }
    }

    private function waitForOutput(): void
    {
        $read = array_values(array_filter(
            [$this->pipes[1] ?? null, $this->pipes[2] ?? null],
            static fn (mixed $pipe): bool => is_resource($pipe)
        ));
        $write = null;
        $except = null;
        if ($read !== [] && stream_select($read, $write, $except, 1, 0) === false) {
            throw new RuntimeException('Cannot read Claude OAuth authorization output');
        }
    }

    private function authorizationUrl(string $output): ?string
    {
        $plain = $this->stripAnsi($output);
        if (preg_match('~https://claude\.com/cai/oauth/authorize\?[^\x00-\x20\x7f]+~', $plain, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    private function stripAnsi(string $value): string
    {
        $value = preg_replace('/\x1B\][^\x07]*(?:\x07|\x1B\\\\)/', '', $value) ?? $value;
        return preg_replace('/\x1B\[[0-9;?]*[A-Za-z]/', '', $value) ?? $value;
    }

    private function closePendingProcess(bool $terminate = true): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($terminate && $status['running']) {
                proc_terminate($this->process);
            }
            proc_close($this->process);
        }
        $this->process = null;
        $this->output = '';
        $this->deadline = 0.0;
    }

    private function claudeBin(): string
    {
        $bin = trim((string) $this->config->get('claude', 'bin', 'claude'));
        if ($bin === '') throw new RuntimeException('Config value claude.bin must not be empty');
        return $bin;
    }

    private function workingDirectory(): string
    {
        $cwd = trim((string) $this->config->get('claude', 'cwd', getenv('HOME') ?: ''));
        if ($cwd === '') throw new RuntimeException('Config value claude.cwd must not be empty');
        return $cwd;
    }

    private function timeoutSeconds(): int
    {
        $timeout_seconds = (int) $this->config->get('claude', 'oauth_timeout_seconds', 930);
        if ($timeout_seconds <= 0) throw new RuntimeException('Config value claude.oauth_timeout_seconds must be positive');
        return $timeout_seconds;
    }

    /** @return array<string, string> */
    private function processEnvironment(): array
    {
        $environment = [];
        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) $environment[$key] = $value;
        }
        unset($environment['CODEX_SID'], $environment['RUNTIME_SID'], $environment['CLAUDE_SID']);
        $environment['BROWSER'] = 'false';
        return $environment;
    }
}
