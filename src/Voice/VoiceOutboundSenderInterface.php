<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

interface VoiceOutboundSenderInterface
{
    /** @return array<string, mixed> */
    public function send(string $runtime_session_id, string $file_path): array;
}
