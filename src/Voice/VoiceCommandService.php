<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use RuntimeException;

final class VoiceCommandService implements VoiceCommandServiceInterface
{
    private const GREETINGS = [
        'Привет! Я голос %s. Ну что, погнали творить что-нибудь великое?',
        'На связи %s. Звучим бодро, отвечаем по делу и не скучаем.',
        'В эфире голос %s. Кофе налит, мысли собраны — можно начинать.',
        'Это %s. Обещаю произносить умные вещи так, будто всё было очевидно.',
        'Голос %s приветствует тебя. Сегодня у нас отличный день для хороших идей.',
        'Привет, я %s. Если ответ можно сказать красиво, я постараюсь.',
    ];

    public function __construct(
        private VoicePreferenceStore $preference,
        private SpeechSynthesizerInterface $synthesizer,
        private VoiceOutboundSenderInterface $sender,
        private string $cache_dir
    ) {
    }

    public function handle(string $runtime_session_id, string $command): string
    {
        $command = trim($command);
        if (preg_match('/^\/voices(?:@\S+)?$/ui', $command) === 1) {
            foreach ($this->preference->allowed() as $index => $voice) {
                $this->sender->send($runtime_session_id, $this->sample($voice, $index), "/voice {$voice}");
            }

            return '';
        }
        if (preg_match('/^\/voice(?:@\S+)?$/ui', $command) === 1) {
            return 'Текущий голос: ' . $this->preference->current() . '.';
        }
        if (preg_match('/^\/voice(?:@\S+)?\s+(\S+)$/ui', $command, $matches) !== 1) {
            throw new RuntimeException('Invalid voice command');
        }

        $voice = $this->preference->select($matches[1]);

        return "Выбран голос: {$voice}.";
    }

    private function sample(string $voice, int $index): string
    {
        $path = rtrim($this->cache_dir, '/') . '/' . hash('sha256', $voice) . '.ogg';
        if (is_file($path)) {
            if (!is_readable($path) || filesize($path) === 0) {
                throw new RuntimeException("Cached voice sample is invalid: {$voice}");
            }

            return $path;
        }
        if (!is_dir($this->cache_dir) && !mkdir($this->cache_dir, 0775, true) && !is_dir($this->cache_dir)) {
            throw new RuntimeException('Cannot create voice sample cache directory');
        }
        $greeting = sprintf(self::GREETINGS[$index % count(self::GREETINGS)], $voice);
        $temporary = $this->synthesizer->synthesize($greeting, $voice);
        $staged = $path . '.tmp-' . bin2hex(random_bytes(6));
        try {
            if (!copy($temporary, $staged) || !rename($staged, $path)) {
                throw new RuntimeException("Cannot cache voice sample: {$voice}");
            }
        } finally {
            if (is_file($staged) && !unlink($staged)) {
                throw new RuntimeException('Cannot remove staged voice sample');
            }
            if (is_file($temporary) && !unlink($temporary)) {
                throw new RuntimeException('Cannot remove temporary voice sample');
            }
        }

        return $path;
    }
}
