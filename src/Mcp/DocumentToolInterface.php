<?php

declare(strict_types=1);

namespace CodexRuntime\Mcp;

interface DocumentToolInterface
{
    /** @return array<string, mixed> */
    public function sendDocument(string $path, string $caption = ''): array;
}
