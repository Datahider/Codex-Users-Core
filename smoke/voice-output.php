#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\Contracts\ClockInterface;
use CodexRuntime\Contracts\RateLimitsProviderInterface;
use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\Document\FileExchangeClientInterface;
use CodexRuntime\LimitMonitor;
use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\HttpClientInterface;
use CodexRuntime\Router\RouterDeliveryClient;
use CodexRuntime\Voice\OpenAiSpeechSynthesizer;
use CodexRuntime\Voice\SpeechHttpClientInterface;
use CodexRuntime\Voice\SpeechSynthesizerInterface;
use CodexRuntime\Voice\VoiceFinalDeliveryService;
use CodexRuntime\Voice\VoiceFinalPlanner;
use CodexRuntime\Voice\VoiceOutboundSenderInterface;
use CodexRuntime\Voice\VoicePreferenceStore;
use CodexRuntime\Voice\VoiceResponseModeStore;
use CodexRuntime\Voice\VoiceSender;
use CodexRuntime\Voice\VoiceSuitabilityClassifierInterface;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $tmp_root = sys_get_temp_dir() . '/codex-voice-output-' . bin2hex(random_bytes(4));
    mkdir($tmp_root, 0775, true);
    $speech_http = new class implements SpeechHttpClientInterface {
        public array $calls = [];
        public function synthesize(string $url, string $api_key, array $payload): string
        {
            $this->calls[] = compact('url', 'api_key', 'payload');
            return 'opus body';
        }
    };
    $speech = new OpenAiSpeechSynthesizer('speech-key', 'gpt-4o-mini-tts', $tmp_root, $speech_http);
    $speech_path = $speech->synthesize('Готово.', 'cedar');
    assertSame('opus body', file_get_contents($speech_path), 'speech file body');
    assertSame('opus', $speech_http->calls[0]['payload']['response_format'] ?? null, 'speech response format');
    assertSame('cedar', $speech_http->calls[0]['payload']['voice'] ?? null, 'speech voice');

    $exchange = new class implements FileExchangeClientInterface {
        public array $calls = [];
        public function uploadFile(string $file_path, string $filename, string $mime): array
        {
            $this->calls[] = compact('file_path', 'filename', 'mime');
            return ['file_id' => 'Voice42', 'url' => 'https://files.ioannidis.ru/Voice42'];
        }
    };
    $router_http = new class implements HttpClientInterface {
        public array $payloads = [];
        public function request(string $method, string $url, array $headers, ?string $body = null): array
        {
            $this->payloads[] = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            return ['status_code' => 200, 'body' => '{"accepted":true,"event_id":88}'];
        }
    };
    $sender = new VoiceSender(
        $exchange,
        new RouterDeliveryClient(new ApiClient('https://router.local', 'token', $router_http))
    );
    $send_result = $sender->send('runtime-1', $speech_path);
    assertSame(true, $send_result['delivered'] ?? null, 'voice sender delivered');
    assertSame('audio/ogg', $exchange->calls[0]['mime'] ?? null, 'voice upload mime');
    assertSame('voice', $router_http->payloads[0]['kind'] ?? null, 'voice outbound kind');
    assertSame('', $router_http->payloads[0]['text'] ?? null, 'voice outbound text');
    assertSame('Voice42', $router_http->payloads[0]['attachments'][0]['file_id'] ?? null, 'voice attachment id');

    $config = new Config(['voice_response' => ['max_characters' => 700]]);
    $modes = new VoiceResponseModeStore($tmp_root . '/modes.json');
    $modes->set('runtime-2', 'voice', 'persistent');
    $classifier = new class implements VoiceSuitabilityClassifierInterface {
        public function classify(string $text): array { return ['voice' => true]; }
    };
    $preference = new VoicePreferenceStore($tmp_root . '/preference.json', 'cedar', ['cedar']);
    $final_speech = new class($tmp_root) implements SpeechSynthesizerInterface {
        public array $calls = [];
        public function __construct(private string $tmp_root) {}
        public function synthesize(string $text, string $voice): string
        {
            $this->calls[] = compact('text', 'voice');
            $path = $this->tmp_root . '/final.ogg';
            file_put_contents($path, 'final voice');
            return $path;
        }
    };
    $final_sender = new class implements VoiceOutboundSenderInterface {
        public array $calls = [];
        public function send(string $runtime_session_id, string $file_path, string $caption = ''): array
        {
            $this->calls[] = ['runtime_session_id' => $runtime_session_id, 'body' => file_get_contents($file_path)];
            return ['delivered' => true];
        }
    };
    $limits_provider = new class implements RateLimitsProviderInterface {
        public int $reads = 0;
        public function read(): array
        {
            $this->reads++;
            return ['primary' => ['usedPercent' => 0, 'resetsAt' => 2000000000]];
        }
    };
    $transport = new class implements TransportClientInterface {
        public function sendMessage(int|string $chatId, string $text, ?int $replyToMessageId = null, ?string $parseMode = null, bool $disableNotification = false): array { throw new RuntimeException('Unexpected text final'); }
        public function sendTranscript(int|string $chatId, string $text): array { throw new RuntimeException('Unexpected transcript'); }
        public function sendWarning(int|string $chatId, string $text): array { throw new RuntimeException('Unexpected warning'); }
        public function sendSystem(int|string $chatId, string $text): array { throw new RuntimeException('Unexpected system'); }
        public function sendChatAction(int|string $chatId, string $action = 'typing'): void {}
    };
    $clock = new class implements ClockInterface { public function now(): int { return 1900000000; } };
    $service = new VoiceFinalDeliveryService(
        new VoiceFinalPlanner($config, $modes, $classifier),
        $preference,
        $final_speech,
        $final_sender,
        new LimitMonitor($config, $limits_provider, $transport, $clock)
    );
    $service->send('runtime-2', 'Краткий ответ.');
    assertSame([['text' => 'Краткий ответ.', 'voice' => 'cedar']], $final_speech->calls, 'final synthesis input');
    assertSame('final voice', $final_sender->calls[0]['body'] ?? null, 'final voice sent');
    assertSame(false, is_file($tmp_root . '/final.ogg'), 'final temporary file removed');
    assertSame(1, $limits_provider->reads, 'limits checked after voice final');

    unlink($speech_path);
    fwrite(STDOUT, "Voice output smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Voice output smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
