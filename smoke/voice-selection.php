#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Voice\SpeechSynthesizerInterface;
use CodexRuntime\Voice\VoiceCommandService;
use CodexRuntime\Voice\VoiceOutboundSenderInterface;
use CodexRuntime\Voice\VoicePreferenceStore;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    foreach ([SpeechSynthesizerInterface::class, VoiceOutboundSenderInterface::class] as $interface) {
        if (!interface_exists($interface)) {
            throw new RuntimeException('Missing contract type ' . $interface);
        }
    }
    foreach ([VoicePreferenceStore::class, VoiceCommandService::class] as $class) {
        if (!class_exists($class)) {
            throw new RuntimeException('Missing contract type ' . $class);
        }
    }

    $tmp_root = sys_get_temp_dir() . '/codex-voice-selection-' . bin2hex(random_bytes(4));
    mkdir($tmp_root, 0775, true);
    $preference = new VoicePreferenceStore(
        $tmp_root . '/voice-preference.json',
        'cedar',
        ['cedar', 'marin', 'nova']
    );
    assertSame('cedar', $preference->current(), 'default voice');

    $synthesizer = new class($tmp_root) implements SpeechSynthesizerInterface {
        public array $calls = [];

        public function __construct(private string $tmp_root)
        {
        }

        public function synthesize(string $text, string $voice): string
        {
            $this->calls[] = ['text' => $text, 'voice' => $voice];
            $path = $this->tmp_root . '/sample.ogg';
            file_put_contents($path, 'voice sample');

            return $path;
        }
    };
    $sender = new class implements VoiceOutboundSenderInterface {
        public array $calls = [];

        public function send(string $runtime_session_id, string $file_path, string $caption = ''): array
        {
            $this->calls[] = [
                'runtime_session_id' => $runtime_session_id,
                'file_path' => $file_path,
                'content' => is_file($file_path) ? file_get_contents($file_path) : null,
                'caption' => $caption,
            ];

            return ['delivered' => true, 'event_id' => 44];
        }
    };
    $service = new VoiceCommandService($preference, $synthesizer, $sender, $tmp_root . '/voice-samples');

    assertSame(
        '',
        $service->handle('runtime-1', '/voices'),
        'voices catalog response'
    );
    assertSame(3, count($synthesizer->calls), 'catalog samples synthesized');
    assertSame(
        ['`/voice cedar`', '`/voice marin`', '`/voice nova`'],
        array_column($sender->calls, 'caption'),
        'copyable captions'
    );
    assertSame(3, count(array_unique(array_column($synthesizer->calls, 'text'))), 'catalog greetings differ');
    foreach ($synthesizer->calls as $call) {
        assertSame(true, str_contains($call['text'], $call['voice']), 'greeting names its voice');
    }
    assertSame('', $service->handle('runtime-1', '/voices'), 'cached catalog response');
    assertSame(3, count($synthesizer->calls), 'cached catalog skips synthesis');
    assertSame(6, count($sender->calls), 'cached samples sent again');
    assertSame('Текущий голос: cedar.', $service->handle('runtime-1', '/voice'), 'current voice');

    assertSame('Выбран голос: nova.', $service->handle('runtime-1', '/voice NOVA'), 'voice selected');
    assertSame('nova', $preference->current(), 'selected voice persisted');
    assertSame(3, count($synthesizer->calls), 'selection skips synthesis');
    assertSame('runtime-1', $sender->calls[0]['runtime_session_id'] ?? null, 'preview destination');
    assertSame('voice sample', $sender->calls[0]['content'] ?? null, 'preview content');

    $reloaded = new VoicePreferenceStore(
        $tmp_root . '/voice-preference.json',
        'cedar',
        ['cedar', 'marin', 'nova']
    );
    assertSame('nova', $reloaded->current(), 'voice survives restart');

    assertThrows(fn () => $service->handle('runtime-1', '/voice unknown'), 'Unknown or disallowed voice: unknown');
    assertSame('nova', $preference->current(), 'invalid voice does not change preference');
    assertSame(3, count($synthesizer->calls), 'invalid voice skips synthesis');

    fwrite(STDOUT, "Voice selection smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Voice selection smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $expected_message): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), $expected_message)) {
            return;
        }
        throw $e;
    }

    throw new RuntimeException("Expected error containing '{$expected_message}'");
}
