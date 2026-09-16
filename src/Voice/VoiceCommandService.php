<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use RuntimeException;

final class VoiceCommandService implements VoiceCommandServiceInterface
{
    private const SAMPLE_TEXT = 'Это пример выбранного голоса.';

    public function __construct(
        private VoicePreferenceStore $preference,
        private SpeechSynthesizerInterface $synthesizer,
        private VoiceOutboundSenderInterface $sender
    ) {
    }

    public function handle(string $runtime_session_id, string $command): string
    {
        $command = trim($command);
        if (preg_match('/^\/voices(?:@\S+)?$/ui', $command) === 1) {
            return 'Доступные голоса: ' . implode(', ', $this->preference->allowed()) . ".\nВыбор: /voice <name>";
        }
        if (preg_match('/^\/voice(?:@\S+)?$/ui', $command) === 1) {
            return 'Текущий голос: ' . $this->preference->current() . '.';
        }
        if (preg_match('/^\/voice(?:@\S+)?\s+(\S+)$/ui', $command, $matches) !== 1) {
            throw new RuntimeException('Invalid voice command');
        }

        $voice = $this->preference->select($matches[1]);
        $file_path = $this->synthesizer->synthesize(self::SAMPLE_TEXT, $voice);
        try {
            $this->sender->send($runtime_session_id, $file_path);
        } finally {
            if (is_file($file_path) && !unlink($file_path)) {
                throw new RuntimeException('Cannot remove temporary voice preview');
            }
        }

        return "Выбран голос: {$voice}.";
    }
}
