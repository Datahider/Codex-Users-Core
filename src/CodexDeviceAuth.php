<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class CodexDeviceAuth implements CodexDeviceAuthInterface
{
    private const DEVICE_URL = 'https://auth.openai.com/codex/device';

    public function __construct(private Config $config)
    {
    }

    public function isLoggedIn(): bool
    {
        [$exit_code, $output] = $this->runStatusCommand();
        if ($exit_code === 0) {
            return true;
        }
        if ($exit_code === 1) {
            return false;
        }

        throw new RuntimeException(
            'Codex login status failed with exit code ' . $exit_code
            . ($output !== '' ? ': ' . $output : '')
        );
    }

    public function login(callable $challenge): void
    {
        $timeout_seconds = (int) $this->config->get('codex', 'device_auth_timeout_seconds', 930);
        if ($timeout_seconds <= 0) {
            throw new RuntimeException('Config value codex.device_auth_timeout_seconds must be positive');
        }

        $process = proc_open(
            [$this->codexBin(), 'login', '--device-auth'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->workingDirectory(),
            $this->processEnvironment()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start Codex device authorization');
        }

        $challenge_sent = false;
        $output = '';
        $exit_code = -1;
        $deadline = microtime(true) + $timeout_seconds;

        try {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            while (true) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    proc_terminate($process);
                    throw new RuntimeException('Codex device authorization timed out');
                }

                $read = [$pipes[1], $pipes[2]];
                $write = null;
                $except = null;
                $wait_seconds = min(1, (int) ceil($remaining));
                $selected = stream_select($read, $write, $except, $wait_seconds, 0);
                if ($selected === false) {
                    throw new RuntimeException('Cannot read Codex device authorization output');
                }

                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if (is_string($chunk) && $chunk !== '') {
                        $output .= $chunk;
                    }
                }
                if (strlen($output) > 65536) {
                    throw new RuntimeException('Codex device authorization output is too large');
                }

                if (!$challenge_sent) {
                    $parsed = $this->parseChallenge($output);
                    if ($parsed !== null) {
                        $challenge($parsed['url'], $parsed['code']);
                        $challenge_sent = true;
                    }
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exit_code = (int) $status['exitcode'];
                    break;
                }
            }

            foreach ([$pipes[1], $pipes[2]] as $stream) {
                $chunk = stream_get_contents($stream);
                if (is_string($chunk) && $chunk !== '') {
                    $output .= $chunk;
                }
            }
            if (!$challenge_sent) {
                $parsed = $this->parseChallenge($output);
                if ($parsed !== null) {
                    $challenge($parsed['url'], $parsed['code']);
                    $challenge_sent = true;
                }
            }

            if ($exit_code !== 0) {
                throw new RuntimeException('Codex device authorization failed with exit code ' . $exit_code);
            }
            if (!$challenge_sent) {
                throw new RuntimeException('Codex device authorization returned no URL and code');
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }

    /** @return array{0:int,1:string} */
    private function runStatusCommand(): array
    {
        $process = proc_open(
            [$this->codexBin(), 'login', 'status'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->workingDirectory(),
            $this->processEnvironment()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start Codex login status');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        return [$exit_code, trim($this->stripAnsi((string) $stdout . "\n" . (string) $stderr))];
    }

    /** @return array{url:string,code:string}|null */
    private function parseChallenge(string $output): ?array
    {
        $plain = $this->stripAnsi($output);
        if (!str_contains($plain, self::DEVICE_URL)) {
            return null;
        }
        if (preg_match('/\b[A-Z0-9]{4}-[A-Z0-9]{5}\b/', $plain, $matches) !== 1) {
            return null;
        }

        return ['url' => self::DEVICE_URL, 'code' => $matches[0]];
    }

    private function stripAnsi(string $value): string
    {
        return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $value) ?? $value;
    }

    private function codexBin(): string
    {
        $bin = trim((string) $this->config->get('codex', 'bin', 'codex'));
        if ($bin === '') {
            throw new RuntimeException('Config value codex.bin must not be empty');
        }

        return $bin;
    }

    private function workingDirectory(): string
    {
        $cwd = trim((string) $this->config->require('codex', 'cwd'));
        if ($cwd === '') {
            throw new RuntimeException('Config value codex.cwd must not be empty');
        }

        return $cwd;
    }

    /** @return array<string, string> */
    private function processEnvironment(): array
    {
        $environment = [];
        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $environment[$key] = $value;
            }
        }
        unset($environment['CODEX_SID'], $environment['RUNTIME_SID']);

        return $environment;
    }
}
