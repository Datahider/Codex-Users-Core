<?php

declare(strict_types=1);

namespace CodexRuntime\ManagerQueue;

use CodexRuntime\Config;
use CodexRuntime\RuntimePaths;
use RuntimeException;

final class ManagerSessionLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private Config $config, public readonly string $session_id)
    {
        if (trim($this->session_id) === '') {
            throw new RuntimeException('Runtime session ID must not be empty');
        }
    }

    public function acquire(): bool
    {
        $path = (new RuntimePaths($this->config))->managerSessionLockFile($this->session_id);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }
        $handle = fopen($path, 'c+e');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
