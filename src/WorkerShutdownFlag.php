<?php

declare(strict_types=1);

namespace CodexRuntime;

final class WorkerShutdownFlag
{
    public function __construct(
        private Config $config,
        private string $section,
        private string $key
    )
    {
    }

    public function requestShutdown(): void
    {
        $flagFile = $this->flagFile();
        $dir = dirname($flagFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($flagFile, date(DATE_ATOM) . PHP_EOL, LOCK_EX);
    }

    public function consumeIfRequested(): bool
    {
        $flagFile = $this->flagFile();
        if (!is_file($flagFile)) {
            return false;
        }

        @unlink($flagFile);

        return true;
    }

    public function clearPending(): void
    {
        $flagFile = $this->flagFile();
        if (is_file($flagFile)) {
            @unlink($flagFile);
        }
    }

    private function flagFile(): string
    {
        return (string) $this->config->require($this->section, $this->key);
    }
}
