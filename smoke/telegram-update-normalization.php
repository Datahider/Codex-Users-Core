#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\Contracts\TransportIngressGatewayInterface;
use CodexRuntime\ControlIngress;
use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\Logger;
use CodexRuntime\Telegram\TelegramApiClient;
use CodexRuntime\Telegram\TelegramUpdateIngress;
use CodexRuntime\Telegram\TelegramUpdateNormalizer;
use CodexRuntime\Telegram\TelegramSessionId;
use CodexRuntime\TransportMessageIngress;

require_once __DIR__ . '/../src/bootstrap.php';

$tmpRoot = sys_get_temp_dir() . '/codex-runtime-telegram-smoke-' . bin2hex(random_bytes(4));
$capturedPayloads = [];
$config = new Config([
    'telegram' => [
        'instance_id' => 'telegram_smoke',
        'allowed_chat_ids' => [
            7001,
            -1009876543200,
            -1009876543210,
        ],
        'bot_token' => 'smoke-token',
        'base_url' => 'https://example.invalid',
        'endpoint_prefix' => 'bot',
    ],
    'storage' => [
        'root' => $tmpRoot,
        'log_file' => $tmpRoot . '/runtime.log',
    ],
]);

$logger = new Logger($tmpRoot . '/runtime.log');
$gateway = new class($capturedPayloads) implements TransportIngressGatewayInterface {
    public function __construct(private mixed &$capturedPayloads)
    {
    }

    public function submitMessage(\CodexRuntime\TransportInboundMessage $message): array
    {
        $payload = $message->toManagerEventPayload();
        $this->capturedPayloads[] = $payload;

        return [
            'accepted' => true,
            'event_id' => 'router-' . count($this->capturedPayloads),
            'action_text' => null,
        ];
    }
};
$ingress = new TelegramUpdateIngress(
    $config,
    $logger,
    new TransportMessageIngress($gateway),
    new ControlIngress(new CommandRepository($config)),
    new TelegramUpdateNormalizer(),
    new TelegramApiClient('smoke-token', 'https://example.invalid', 'bot'),
    'telegram_smoke'
);

try {
    $cases = [
        [
            'name' => 'private',
            'fixture_path' => __DIR__ . '/fixtures/telegram/private-message.json',
            'expected' => [
                'channel_id' => 7001,
                'session_id' => 'telegram_smoke:d7001',
                'sender_id' => 7001,
                'message_thread_id' => null,
                'text' => 'private ping',
            ],
        ],
        [
            'name' => 'group',
            'fixture_path' => __DIR__ . '/fixtures/telegram/group-message.json',
            'expected' => [
                'channel_id' => -1009876543200,
                'session_id' => 'telegram_smoke:g1009876543200',
                'sender_id' => 7002,
                'message_thread_id' => null,
                'text' => 'group ping',
            ],
        ],
        [
            'name' => 'topic',
            'fixture_path' => __DIR__ . '/fixtures/telegram/plain-text-update.json',
            'expected' => [
                'channel_id' => -1009876543210,
                'session_id' => 'telegram_smoke:g1009876543210_t99',
                'message_thread_id' => 99,
                'sender_id' => 7001,
                'text' => 'ping from telegram',
            ],
        ],
    ];

    foreach ($cases as $case) {
        $fixture = loadFixture($case['fixture_path']);
        $result = $ingress->ingest($fixture);

        assertSame(true, $result['accepted'] ?? null, $case['name'] . ' update should be accepted');
        assertNotEmpty($result['event_id'] ?? null, $case['name'] . ' event id should be returned');
        assertSame(null, $result['reason'] ?? null, $case['name'] . ' accepted update should not include a reason');

        $event = $capturedPayloads[count($capturedPayloads) - 1] ?? null;
        if (!is_array($event)) {
            throw new RuntimeException('Transport ingress did not capture payload');
        }

        assertSame('user_message', $event['type'] ?? null, $case['name'] . ' event type');
        assertSame($case['expected']['text'], $event['text'] ?? null, $case['name'] . ' normalized text');
        assertSame($case['expected']['session_id'], $event['session_id'] ?? null, $case['name'] . ' runtime session id');
        assertSame('telegram', $event['meta']['transport'] ?? null, $case['name'] . ' transport meta');
        assertSame('message', $event['meta']['update_type'] ?? null, $case['name'] . ' update type meta');
        assertSame($case['expected']['sender_id'], $event['meta']['sender_id'] ?? null, $case['name'] . ' sender id meta');

        $resolved = TelegramSessionId::resolve((string) $event['session_id'], 'telegram_smoke');
        assertNotEmpty($resolved, $case['name'] . ' resolved session target');
        assertSame((string) $case['expected']['channel_id'], $resolved['chat_id'] ?? null, $case['name'] . ' resolved chat id');
        assertSame($case['expected']['message_thread_id'], $resolved['thread_id'] ?? null, $case['name'] . ' resolved thread id');

        fwrite(STDOUT, "Telegram update normalization smoke: OK ({$case['name']})\n");
        fwrite(STDOUT, "Fixture: {$case['fixture_path']}\n");
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Telegram update normalization smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    deleteTree($tmpRoot);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        $expectedText = var_export($expected, true);
        $actualText = var_export($actual, true);
        throw new RuntimeException("Assertion failed for {$label}: expected {$expectedText}, got {$actualText}");
    }
}

function assertNotEmpty(mixed $value, string $label): void
{
    if ($value === null || $value === '') {
        throw new RuntimeException("Assertion failed for {$label}: value is empty");
    }
}

function deleteTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemPath = $path . '/' . $item;
        if (is_dir($itemPath)) {
            deleteTree($itemPath);
            continue;
        }

        unlink($itemPath);
    }

    rmdir($path);
}

/**
 * @return array<string, mixed>
 */
function loadFixture(string $fixturePath): array
{
    $fixtureRaw = file_get_contents($fixturePath);
    if ($fixtureRaw === false) {
        throw new RuntimeException("Cannot read fixture: {$fixturePath}");
    }

    $fixture = json_decode($fixtureRaw, true);
    if (!is_array($fixture)) {
        throw new RuntimeException("Invalid JSON fixture: {$fixturePath}");
    }

    return $fixture;
}
