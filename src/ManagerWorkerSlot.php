<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class ManagerWorkerSlot
{
    /** @var resource|null */
    private $handle = null;
    private ?int $slot_number = null;
    private ?string $path = null;
    private int $max_workers;

    public function __construct(private Config $config)
    {
        $this->max_workers = (int) $this->config->require('manager_queue', 'max_workers');
        if ($this->max_workers < 1) {
            throw new RuntimeException('manager_queue.max_workers must be greater than zero');
        }
    }

    public function acquire(): bool
    {
        if (is_resource($this->handle)) {
            throw new RuntimeException('Manager worker slot is already acquired');
        }

        $paths = new RuntimePaths($this->config);
        if (!is_dir($paths->runDir()) && !mkdir($paths->runDir(), 0775, true) && !is_dir($paths->runDir())) {
            throw new RuntimeException("Cannot create directory {$paths->runDir()}");
        }

        for ($number = 1; $number <= $this->max_workers; $number++) {
            $path = $paths->managerWorkerSlotFile($number);
            $handle = fopen($path, 'c+e');
            if ($handle === false) {
                throw new RuntimeException("Cannot open {$path}");
            }

            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                continue;
            }

            ftruncate($handle, 0);
            fwrite($handle, (string) getmypid());
            fflush($handle);
            $this->handle = $handle;
            $this->slot_number = $number;
            $this->path = $path;
            return true;
        }

        return false;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
        $this->slot_number = null;
    }

    public function number(): ?int
    {
        return $this->slot_number;
    }

    public function capacity(): int
    {
        return $this->max_workers;
    }

    /**
     * @return array{acquired:bool,slot:?int,path:?string,inode:?int,device:?int,exclusive_lock_observed:bool}
     */
    public function diagnostics(): array
    {
        $stat = $this->path !== null ? stat($this->path) : false;
        $exclusive_lock_observed = false;

        if ($this->path !== null) {
            $probe = fopen($this->path, 'c+e');
            if ($probe === false) {
                throw new RuntimeException("Cannot open {$this->path} for slot diagnostics");
            }

            if (flock($probe, LOCK_EX | LOCK_NB)) {
                flock($probe, LOCK_UN);
            } else {
                $exclusive_lock_observed = true;
            }
            fclose($probe);
        }

        return [
            'acquired' => is_resource($this->handle),
            'slot' => $this->slot_number,
            'path' => $this->path,
            'inode' => is_array($stat) ? (int) $stat['ino'] : null,
            'device' => is_array($stat) ? (int) $stat['dev'] : null,
            'exclusive_lock_observed' => $exclusive_lock_observed,
        ];
    }

    public function __destruct()
    {
        $this->release();
    }
}
