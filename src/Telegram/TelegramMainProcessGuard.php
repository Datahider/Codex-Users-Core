<?php

declare(strict_types=1);

namespace CodexRuntime\Telegram;

use CodexRuntime\Config;
use CodexRuntime\Logger;
use RuntimeException;

final class TelegramMainProcessGuard
{
    private $lockHandle = null;

    public function __construct(
        private Config $config,
        private Logger $logger
    ) {
    }

    public function acquire(): void
    {
        $lockFile = $this->lockFile();
        $pidFile = $this->pidFile();

        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0775, true);
        }

        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$lockFile}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $existingPid = is_file($pidFile) ? trim((string) file_get_contents($pidFile)) : 'unknown';
            $this->logger->error('Another Telegram transport instance is already running', [
                'pid' => $existingPid,
            ]);
            throw new RuntimeException('Another Telegram transport instance is already running');
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        file_put_contents($pidFile, (string) getmypid());
        $this->lockHandle = $handle;

        register_shutdown_function(function () use ($pidFile): void {
            if ($this->lockHandle !== null) {
                flock($this->lockHandle, LOCK_UN);
                fclose($this->lockHandle);
                $this->lockHandle = null;
            }

            if (is_file($pidFile)) {
                @unlink($pidFile);
            }
        });
    }

    private function lockFile(): string
    {
        $configured = trim((string) $this->config->get('telegram', 'transport_lock_file', ''));
        if ($configured !== '') {
            return $configured;
        }

        return $this->runDir() . '/telegram-transport.lock';
    }

    private function pidFile(): string
    {
        $configured = trim((string) $this->config->get('telegram', 'transport_pid_file', ''));
        if ($configured !== '') {
            return $configured;
        }

        return $this->runDir() . '/telegram-transport.pid';
    }

    private function runDir(): string
    {
        $managerStateFile = (string) $this->config->require('storage', 'manager_state_file');

        return dirname(dirname($managerStateFile)) . '/run';
    }
}
