#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\ControlIngress;
use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\Max\MaxSessionId;
use CodexRuntime\Max\MaxTransportClient;
use CodexRuntime\Max\MaxTransportStateStore;
use CodexRuntime\Max\MaxUpdateIngress;
use CodexRuntime\Max\MaxUpdateNormalizer;
use CodexRuntime\Max\MaxWebhookIngress;
use CodexRuntime\TransportMessageIngress;
use MaxApi\BotApi;

require_once __DIR__ . '/../src/bootstrap.php';

$fixturePath = __DIR__ . '/fixtures/max/plain-text-update.json';
$fixture = file_get_contents($fixturePath);
if ($fixture === false) {
    fwrite(STDERR, "Cannot read fixture: {$fixturePath}\n");
    exit(1);
}

$tmpRoot = sys_get_temp_dir() . '/codex-runtime-max-smoke-' . bin2hex(random_bytes(4));
$queueRoot = $tmpRoot . '/manager-queue';
$config = new Config([
    'transport' => [
        'allowed_channel_ids' => ['chat-42'],
    ],
    'storage' => [
        'root' => $tmpRoot,
        'manager_state_file' => $tmpRoot . '/state/manager-state.json',
        'log_file' => $tmpRoot . '/runtime.log',
    ],
    'max' => [
        'instance_id' => 'max_smoke',
        'transport_state_file' => $tmpRoot . '/state/max-transport-state.json',
        'webhook_secret' => '',
        'webhook_header' => 'X-Max-Bot-Api-Secret',
    ],
]);

$logger = new Logger($tmpRoot . '/runtime.log');
$repository = new EventRepository($config);
$ingress = new MaxWebhookIngress(
    $config,
    new MaxUpdateIngress(
        $config,
        $logger,
        new TransportMessageIngress($repository),
        new ControlIngress(new CommandRepository($config)),
        new MaxUpdateNormalizer(),
        new MaxTransportClient(new BotApi('smoke-token')),
        new MaxTransportStateStore($config),
        'max_smoke'
    )
);

try {
    $result = $ingress->ingest($fixture);

    assertSame(true, $result['accepted'] ?? null, 'webhook should be accepted');
    assertNotEmpty($result['event_id'] ?? null, 'event id should be returned');
    assertSame(null, $result['reason'] ?? null, 'accepted webhook should not include a reason');

    $eventPath = $queueRoot . '/new/' . $result['event_id'] . '.json';
    assertFileExists($eventPath, 'manager queue event should be created');

    $eventRaw = file_get_contents($eventPath);
    if ($eventRaw === false) {
        throw new RuntimeException("Cannot read event payload: {$eventPath}");
    }

    $event = json_decode($eventRaw, true);
    if (!is_array($event)) {
        throw new RuntimeException("Invalid JSON in event payload: {$eventPath}");
    }

    assertSame('user_message', $event['type'] ?? null, 'event type');
    assertSame('ping from max', $event['text'] ?? null, 'normalized text');
    assertSame('max_smoke:dchat-42', $event['session_id'] ?? null, 'runtime session id');
    assertSame('message_created', $event['meta']['update_type'] ?? null, 'update type meta');
    assertSame('user-7', $event['meta']['sender_id'] ?? null, 'sender id meta');

    $resolved = MaxSessionId::resolve((string) $event['session_id'], 'max_smoke');
    assertNotEmpty($resolved, 'resolved session target');
    assertSame('chat-42', $resolved['chat_id'] ?? null, 'resolved chat id');

    fwrite(STDOUT, "MAX webhook normalization smoke: OK\n");
    fwrite(STDOUT, "Fixture: {$fixturePath}\n");
    fwrite(STDOUT, "Queued event: {$eventPath}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "MAX webhook normalization smoke failed: {$e->getMessage()}\n");
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

function assertFileExists(string $path, string $label): void
{
    if (!is_file($path)) {
        throw new RuntimeException("Assertion failed for {$label}: missing {$path}");
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
