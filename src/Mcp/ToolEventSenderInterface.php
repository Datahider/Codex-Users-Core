<?php

declare(strict_types=1);

namespace CodexRuntime\Mcp;

interface ToolEventSenderInterface
{
    /** @param array<string, mixed> $meta */
    public function sendToolEvent(string $runtime_session_id, array $meta): void;
}
