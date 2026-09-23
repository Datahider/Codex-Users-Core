<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class RuntimePaths
{
    private readonly string $root;

    public function __construct(private Config $config)
    {
        $root = rtrim(trim((string) $this->config->require('storage', 'root')), '/');
        if ($root === '') {
            throw new RuntimeException('storage.root must not be empty');
        }

        $this->root = $root;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function runDir(): string
    {
        return $this->root . '/run';
    }

    public function stateDir(): string
    {
        return $this->root . '/state';
    }

    public function logDir(): string
    {
        return $this->root . '/log';
    }

    public function tmpDir(): string
    {
        return $this->root . '/tmp';
    }

    public function logFile(): string
    {
        return $this->logDir() . '/runtime.log';
    }

    public function managerStateFile(): string
    {
        return $this->stateDir() . '/manager-state.json';
    }

    public function stateFile(): string
    {
        return $this->stateDir() . '/state.json';
    }

    public function routerStateFile(): string
    {
        return $this->stateDir() . '/router-state.json';
    }

    public function activeTurnFile(): string
    {
        return $this->stateDir() . '/active-turn.json';
    }

    public function voiceResponseModesFile(): string
    {
        return $this->stateDir() . '/voice-response-modes.json';
    }

    public function voicePreferenceFile(): string
    {
        return $this->stateDir() . '/voice-preference.json';
    }

    public function voiceSamplesDir(): string
    {
        return $this->root . '/voice-samples';
    }

    public function codexDebugDir(): string
    {
        return $this->root . '/codex-debug';
    }

    public function attachmentsDir(): string
    {
        return $this->root . '/attachments';
    }

    public function mainLockFile(): string
    {
        return $this->runDir() . '/core-main.lock';
    }

    public function mainPidFile(): string
    {
        return $this->runDir() . '/core-main.pid';
    }

    public function workerLockFile(string $worker): string
    {
        return $this->runDir() . '/' . $this->normalizeWorkerName($worker) . '.lock';
    }

    public function workerPidFile(string $worker): string
    {
        return $this->runDir() . '/' . $this->normalizeWorkerName($worker) . '.pid';
    }

    public function workerShutdownFlagFile(string $worker): string
    {
        return $this->runDir() . '/' . $this->normalizeWorkerName($worker) . '.shutdown.flag';
    }

    public function managerWorkerSlotFile(int $slot_number): string
    {
        if ($slot_number < 1) {
            throw new RuntimeException('Manager worker slot number must be greater than zero');
        }

        return $this->runDir() . '/manager-worker-slot-' . $slot_number . '.lock';
    }

    public function managerSessionLockFile(string $runtime_session_id): string
    {
        $runtime_session_id = trim($runtime_session_id);
        if ($runtime_session_id === '') {
            throw new RuntimeException('Runtime session ID must not be empty');
        }

        return $this->runDir() . '/manager-session-' . hash('sha256', $runtime_session_id) . '.lock';
    }

    private function normalizeWorkerName(string $worker): string
    {
        $normalized = trim($worker);
        if ($normalized === '') {
            throw new RuntimeException('Worker name must not be empty');
        }

        return str_replace('_', '-', $normalized);
    }
}
