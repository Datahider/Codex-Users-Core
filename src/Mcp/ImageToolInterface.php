<?php

declare(strict_types=1);

namespace CodexRuntime\Mcp;

interface ImageToolInterface
{
    /** @return array<string, mixed> */
    public function sendImage(string $path, string $caption = ''): array;
}
