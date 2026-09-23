<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

final class UnavailableVoiceCommandService implements VoiceCommandServiceInterface
{
    /** @param list<string> $missing_values */
    public function __construct(private array $missing_values)
    {
    }

    public function handle(string $runtime_session_id, string $command): string
    {
        return 'Голосовые ответы не настроены. Не хватает параметров конфигурации: '
            . implode(', ', $this->missing_values) . '.';
    }
}
