<?php

declare(strict_types=1);

namespace CodexRuntime\Audio;

interface AudioTranscriberInterface
{
    public function transcribe(string $file_path): string;
}
