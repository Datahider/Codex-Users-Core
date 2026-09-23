<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use CodexRuntime\Contracts\FinalResponseDeliveryInterface;

final class UnavailableVoiceFinalDeliveryService implements FinalResponseDeliveryInterface
{
    /** @param list<string> $missing_values */
    public function __construct(
        private VoiceResponseModeStore $mode_store,
        private FinalResponseDeliveryInterface $text_delivery,
        private array $missing_values
    ) {
    }

    public function send(string $runtime_session_id, string $text): array
    {
        if ($this->mode_store->consume($runtime_session_id) === 'voice') {
            $text = 'Голосовые ответы не настроены. Не хватает параметров конфигурации: '
                . implode(', ', $this->missing_values) . ".\n\n" . $text;
        }

        return $this->text_delivery->send($runtime_session_id, $text);
    }
}
