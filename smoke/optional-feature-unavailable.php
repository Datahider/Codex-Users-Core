#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Audio\UnavailableAudioTranscriber;
use CodexRuntime\Contracts\FinalResponseDeliveryInterface;
use CodexRuntime\Mcp\UnavailableFileTool;
use CodexRuntime\Voice\UnavailableVoiceCommandService;
use CodexRuntime\Voice\UnavailableVoiceFinalDeliveryService;
use CodexRuntime\Voice\VoiceResponseModeStore;

require dirname(__DIR__) . '/src/bootstrap.php';

$file_message = 'Отправка файлов не настроена. Не хватает параметров конфигурации: file_exchange.base_url, file_exchange.token.';
$file_tool = new UnavailableFileTool(['file_exchange.base_url', 'file_exchange.token']);
assertThrows(static fn () => $file_tool->sendDocument('/tmp/file'), $file_message);
assertThrows(static fn () => $file_tool->sendImage('/tmp/image'), $file_message);

$transcription_message = 'Распознавание голосовых сообщений не настроено. Не хватает параметров конфигурации: transcription.api_key, transcription.model.';
$transcriber = new UnavailableAudioTranscriber(['transcription.api_key', 'transcription.model']);
assertThrows(static fn () => $transcriber->transcribe('/tmp/voice'), $transcription_message);

$voice_message = 'Голосовые ответы не настроены. Не хватает параметров конфигурации: speech.model, voice_response.default_voice.';
$voice_commands = new UnavailableVoiceCommandService(['speech.model', 'voice_response.default_voice']);
assertSame($voice_message, $voice_commands->handle('session', '/voices'), 'voice command explanation');

$mode_path = sys_get_temp_dir() . '/core-unavailable-voice-mode-' . bin2hex(random_bytes(4)) . '.json';
$mode_store = new VoiceResponseModeStore($mode_path);
$mode_store->set('session', 'voice', 'once');
$text_delivery = new class implements FinalResponseDeliveryInterface {
    public string $text = '';

    public function send(string $runtime_session_id, string $text): array
    {
        $this->text = $text;
        return ['message_id' => 1];
    }
};
$final_delivery = new UnavailableVoiceFinalDeliveryService(
    $mode_store,
    $text_delivery,
    ['speech.model', 'voice_response.default_voice']
);
$final_delivery->send('session', 'Обычный ответ.');
assertSame($voice_message . "\n\nОбычный ответ.", $text_delivery->text, 'voice final falls back with explanation');
if (is_file($mode_path)) {
    unlink($mode_path);
}

fwrite(STDOUT, "Optional feature unavailable smoke: OK\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        assertSame($message, $error->getMessage(), 'exception message');
        return;
    }
    throw new RuntimeException('Expected exception was not thrown');
}
