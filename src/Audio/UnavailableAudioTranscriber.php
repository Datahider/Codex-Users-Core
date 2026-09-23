<?php

declare(strict_types=1);

namespace CodexRuntime\Audio;

use CodexRuntime\OptionalFeatureUnavailableException;

final class UnavailableAudioTranscriber implements AudioTranscriberInterface
{
    /** @param list<string> $missing_values */
    public function __construct(private array $missing_values)
    {
    }

    public function transcribe(string $file_path): string
    {
        throw new OptionalFeatureUnavailableException(
            'Распознавание голосовых сообщений не настроено. Не хватает параметров конфигурации: '
            . implode(', ', $this->missing_values) . '.'
        );
    }
}
