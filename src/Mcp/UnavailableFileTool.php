<?php

declare(strict_types=1);

namespace CodexRuntime\Mcp;

use CodexRuntime\OptionalFeatureUnavailableException;

final class UnavailableFileTool implements DocumentToolInterface, ImageToolInterface
{
    /** @param list<string> $missing_values */
    public function __construct(private array $missing_values)
    {
    }

    public function sendDocument(string $path, string $caption = ''): array
    {
        $this->fail();
    }

    public function sendImage(string $path, string $caption = ''): array
    {
        $this->fail();
    }

    private function fail(): never
    {
        throw new OptionalFeatureUnavailableException(
            'Отправка файлов не настроена. Не хватает параметров конфигурации: '
            . implode(', ', $this->missing_values) . '.'
        );
    }
}
