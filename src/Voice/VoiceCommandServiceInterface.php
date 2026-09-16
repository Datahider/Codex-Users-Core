<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

interface VoiceCommandServiceInterface
{
    public function handle(string $runtime_session_id, string $command): string;
}
