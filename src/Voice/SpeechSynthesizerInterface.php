<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

interface SpeechSynthesizerInterface
{
    public function synthesize(string $text, string $voice): string;
}
