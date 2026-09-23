<?php

declare(strict_types=1);

namespace CodexRuntime;

final class OptionalFeatureConfig
{
    public function __construct(private Config $config)
    {
    }

    /** @return list<string> */
    public function missingTranscriptionValues(): array
    {
        return $this->missing([
            ['transcription', 'api_key'],
            ['transcription', 'model'],
        ]);
    }

    /** @return list<string> */
    public function missingFileExchangeValues(): array
    {
        return $this->missing([
            ['file_exchange', 'base_url'],
            ['file_exchange', 'token'],
        ]);
    }

    /** @return list<string> */
    public function missingVoiceResponseValues(): array
    {
        return $this->missing([
            ['transcription', 'api_key'],
            ['speech', 'model'],
            ['voice_response', 'default_voice'],
            ['voice_response', 'allowed_voices'],
            ['file_exchange', 'base_url'],
            ['file_exchange', 'token'],
        ]);
    }

    public function codexBin(): string
    {
        return trim((string) $this->config->get('codex', 'bin', 'codex'));
    }

    /**
     * @param list<array{0:string,1:string}> $keys
     * @return list<string>
     */
    private function missing(array $keys): array
    {
        $missing = [];
        foreach ($keys as [$section, $key]) {
            $value = $this->config->get($section, $key);
            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                $missing[] = "{$section}.{$key}";
            }
        }

        return $missing;
    }
}
