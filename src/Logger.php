<?php

declare(strict_types=1);

namespace CodexRuntime;

final class Logger
{
    public function __construct(private string $path)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $line = sprintf(
            "[%s] %s %s",
            date('Y-m-d H:i:s'),
            $level,
            $message
        );

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }

        file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        if (defined('STDOUT')) {
            fwrite(STDOUT, $line . PHP_EOL);
        }
    }
}
