<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

interface VoiceSuitabilityClassifierInterface
{
    /** @return array{voice: bool, reason?: string} */
    public function classify(string $text): array;
}
