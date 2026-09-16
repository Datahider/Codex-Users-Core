<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use CodexRuntime\Config;
use RuntimeException;

final class VoiceFinalPlanner
{
    public function __construct(
        private Config $config,
        private VoiceResponseModeStore $modes,
        private VoiceSuitabilityClassifierInterface $classifier
    ) {
    }

    /** @return array{kind: string, text: string} */
    public function plan(string $runtime_session_id, string $kind, string $text): array
    {
        if ($kind !== 'final') {
            return ['kind' => $kind, 'text' => $text];
        }
        if ($this->modes->consume($runtime_session_id) !== 'voice') {
            return ['kind' => 'final', 'text' => $text];
        }
        if (preg_match('/(?:^|\R)\s*(?:```|~~~)/u', $text) === 1) {
            return $this->textFallback('ответ содержит блок кода', $text);
        }

        $max_characters = $this->config->get('voice_response', 'max_characters', 700);
        if (!is_int($max_characters) || $max_characters <= 0) {
            throw new RuntimeException('Config value voice_response.max_characters must be a positive integer');
        }
        preg_match_all('/./us', $text, $characters);
        if (count($characters[0]) > $max_characters) {
            return $this->textFallback('ответ слишком длинный для голосового сообщения', $text);
        }

        $decision = $this->classifier->classify($text);
        if (!array_key_exists('voice', $decision) || !is_bool($decision['voice'])) {
            throw new RuntimeException('Invalid voice classifier decision');
        }
        if ($decision['voice']) {
            return ['kind' => 'voice', 'text' => $text];
        }
        $reason = trim((string) ($decision['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Voice classifier rejection reason is required');
        }

        return $this->textFallback(rtrim($reason, '.!?' . " \t\n\r\0\x0B"), $text);
    }

    /** @return array{kind: string, text: string} */
    private function textFallback(string $reason, string $text): array
    {
        return [
            'kind' => 'final',
            'text' => "Отправляю текстом: {$reason}.\n\n{$text}",
        ];
    }
}
