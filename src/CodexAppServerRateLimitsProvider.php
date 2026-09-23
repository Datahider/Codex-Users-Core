<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\RateLimitsProviderInterface;
use RuntimeException;

final class CodexAppServerRateLimitsProvider implements RateLimitsProviderInterface
{
    public function __construct(private Config $config)
    {
    }

    public function read(): array
    {
        $codex_bin = trim((string) $this->config->get('codex', 'bin', 'codex'));
        $timeout_seconds = (int) $this->config->get('limits', 'request_timeout_seconds', 10);
        if ($timeout_seconds <= 0) {
            throw new RuntimeException('Config value limits.request_timeout_seconds must be positive');
        }

        $process = proc_open(
            [$codex_bin, 'app-server'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            trim((string) $this->config->get('codex', 'cwd', '/home/web'))
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start codex app-server');
        }

        try {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + $timeout_seconds;

            $this->writeMessage($pipes[0], [
                'method' => 'initialize',
                'id' => 0,
                'params' => [
                    'clientInfo' => [
                        'name' => 'codex_multitenant_core',
                        'title' => 'Codex Multitenant Core',
                        'version' => '1.0.0',
                    ],
                ],
            ]);
            $this->readResponse($pipes[1], $pipes[2], 0, $deadline);

            $this->writeMessage($pipes[0], ['method' => 'initialized', 'params' => []]);
            $this->writeMessage($pipes[0], ['method' => 'account/rateLimits/read', 'id' => 1]);
            $response = $this->readResponse($pipes[1], $pipes[2], 1, $deadline);

            $rate_limits = $response['result']['rateLimits'] ?? null;
            if (!is_array($rate_limits)) {
                throw new RuntimeException('codex app-server returned no rateLimits object');
            }

            return $rate_limits;
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            proc_close($process);
        }
    }

    /**
     * @param resource $stdin
     * @param array<string, mixed> $message
     */
    private function writeMessage($stdin, array $message): void
    {
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $written = fwrite($stdin, $json . "\n");
        if ($written === false || $written !== strlen($json) + 1) {
            throw new RuntimeException('Cannot write JSON-RPC request to codex app-server');
        }
        fflush($stdin);
    }

    /**
     * @param resource $stdout
     * @param resource $stderr
     * @return array<string, mixed>
     */
    private function readResponse($stdout, $stderr, int $expected_id, float $deadline): array
    {
        $stderr_text = '';
        while (microtime(true) < $deadline) {
            $read = [$stdout, $stderr];
            $write = null;
            $except = null;
            $remaining = max(0.0, $deadline - microtime(true));
            $seconds = (int) floor($remaining);
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $selected = stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                throw new RuntimeException('Cannot read codex app-server response');
            }
            if ($selected === 0) {
                break;
            }

            foreach ($read as $stream) {
                if ($stream === $stderr) {
                    $chunk = stream_get_contents($stderr);
                    if (is_string($chunk)) {
                        $stderr_text .= $chunk;
                    }
                    continue;
                }

                while (($line = fgets($stdout)) !== false) {
                    $message = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($message) || ($message['id'] ?? null) !== $expected_id) {
                        continue;
                    }
                    if (isset($message['error'])) {
                        throw new RuntimeException('codex app-server JSON-RPC error: ' . json_encode($message['error'], JSON_UNESCAPED_SLASHES));
                    }

                    return $message;
                }
            }
        }

        $details = trim($stderr_text);
        throw new RuntimeException(
            'Timed out waiting for codex app-server response'
            . ($details !== '' ? ": {$details}" : '')
        );
    }
}
