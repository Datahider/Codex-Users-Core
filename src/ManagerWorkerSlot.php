<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class ManagerWorkerSlot
{
    /** @var resource|null */
    private $handle = null;
    private ?int $slot_number = null;
    private int $max_workers;

    public function __construct(private Config $config)
    {
        $this->max_workers = (int) $this->config->get('manager_queue', 'max_workers', 1);
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

    public function __destruct()
    {
        $this->release();
    }
}
