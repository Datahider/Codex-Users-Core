<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use RuntimeException;

final class VoicePreferenceStore
{
    /** @param list<string> $allowed_voices */
    public function __construct(
        private string $path,
        private string $default_voice,
        private array $allowed_voices
    ) {
        $this->default_voice = $this->normalize($this->default_voice);
        $this->allowed_voices = array_values(array_unique(array_map($this->normalize(...), $this->allowed_voices)));
        if ($this->allowed_voices === [] || !in_array($this->default_voice, $this->allowed_voices, true)) {
            throw new RuntimeException('Default voice must occur in allowed voices');
        }
    }

    public function current(): string
    {
        if (!is_file($this->path)) {
            return $this->default_voice;
        }
        $state = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        $voice = is_array($state) ? $this->normalize((string) ($state['voice'] ?? '')) : '';
        if (!in_array($voice, $this->allowed_voices, true)) {
            throw new RuntimeException('Stored voice is not allowed');
        }

        return $voice;
    }

    public function select(string $voice): string
    {
        $voice = $this->normalize($voice);
        if (!in_array($voice, $this->allowed_voices, true)) {
            throw new RuntimeException("Unknown or disallowed voice: {$voice}");
        }
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create voice preference directory');
        }
        $json = json_encode(['voice' => $voice], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot write voice preference');
        }

        return $voice;
    }

    /** @return list<string> */
    public function allowed(): array
    {
        return $this->allowed_voices;
    }

    private function normalize(string $voice): string
    {
        return strtolower(trim($voice));
    }
}
