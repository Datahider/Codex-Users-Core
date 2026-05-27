<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class MainProcessGuard
{
    private $lockHandle = null;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private string $lockConfigKey = 'bot_lock_file',
        private string $pidConfigKey = 'bot_pid_file'
    ) {
    }

    public function acquire(): void
    {
        $paths = new RuntimePaths($this->config);
        $lockFile = (string) $this->config->get('background', $this->lockConfigKey, $paths->mainLockFile());
        $pidFile = (string) $this->config->get('background', $this->pidConfigKey, $paths->mainPidFile());

        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0775, true);
        }

        $pidDir = dirname($pidFile);
        if (!is_dir($pidDir)) {
            mkdir($pidDir, 0775, true);
        }

        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$lockFile}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $existingPid = is_file($pidFile) ? trim((string) file_get_contents($pidFile)) : 'unknown';
            $this->logger->error('Another main process instance is already running', [
                'pid' => $existingPid,
            ]);
            throw new RuntimeException('Another main process instance is already running');
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
}
