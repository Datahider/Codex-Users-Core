#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\Config;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\OutboundQueue\MessageRepository;
use CodexRuntime\SessionRouteStore;
use CodexRuntime\TransportInboundMessage;
use CodexRuntime\TransportMessageIngress;

$configPath = $argv[1] ?? (__DIR__ . '/../config/config.php');
$channelId = $argv[2] ?? 'cli-test';
$pollIntervalUs = isset($argv[3]) ? max(50000, (int) $argv[3] * 1000) : 250000;

$config = Config::fromFile($configPath);
$ingress = new TransportMessageIngress(new EventRepository($config));
$outbound = new MessageRepository($config);
$routes = new SessionRouteStore($config);

$stdinBuffer = '';
$seen = [];

stream_set_blocking(STDIN, false);

fwrite(STDOUT, "CLI transport ready for channel {$channelId}. Type text and press Enter.\n");

while (true) {
    drainOutboundQueue($outbound, $routes, $channelId, $seen);

    $read = [STDIN];
    $write = null;
    $except = null;
    $changed = @stream_select($read, $write, $except, 0, $pollIntervalUs);

    if ($changed === false) {
        usleep($pollIntervalUs);
        continue;
    }

    if ($changed === 0) {
        if (feof(STDIN)) {
            break;
        }
        continue;
    }

    $chunk = stream_get_contents(STDIN);
    if ($chunk === false) {
        continue;
    }

    if ($chunk === '' && feof(STDIN)) {
        break;
    }

    $stdinBuffer .= $chunk;
    while (($pos = strpos($stdinBuffer, "\n")) !== false) {
        $line = trim(substr($stdinBuffer, 0, $pos));
        $stdinBuffer = substr($stdinBuffer, $pos + 1);
        if ($line === '') {
            continue;
        }

        $route = chatRoute($config, $channelId);
        $sessionId = trim((string) (($route['session_id'] ?? '')));
        if ($sessionId === '') {
            fwrite(STDERR, "CLI route is not configured for {$channelId}.\n");
            continue;
        }

        $ingress->enqueueUserMessage(new TransportInboundMessage(
            channelId: $channelId,
            text: $line,
            sessionId: $sessionId,
            channelType: 'cli'
        ), false);
    }
}

drainOutboundQueue($outbound, $routes, $channelId, $seen);

function drainOutboundQueue(MessageRepository $outbound, SessionRouteStore $routes, string $channelId, array &$seen): void
{
    foreach ($outbound->listPendingPaths() as $path) {
        if (isset($seen[$path])) {
            continue;
        }

        $message = $outbound->loadMessageIfPresent($path);
        if ($message === null) {
            continue;
        }

        $sessionId = trim((string) ($message['session_id'] ?? ''));
        if ($sessionId === '') {
            continue;
        }

        $route = $routes->routeForSession($sessionId);
        if (!is_array($route)) {
            continue;
        }

        if ((string) ($route['channel_id'] ?? '') !== $channelId) {
            continue;
        }

        $seen[$path] = true;
        $type = (string) ($message['type'] ?? 'message');
        if ($type === 'message') {
            $text = trim((string) ($message['text'] ?? ''));
            if ($text !== '') {
                $prefix = ((string) ($message['kind'] ?? '') === 'commentary' || !empty($message['disable_notification'])) ? '// ' : '';
                fwrite(STDOUT, $prefix . $text . PHP_EOL);
            }
        }

        $outbound->markDone($path);
    }
}

function chatRoute(Config $config, int|string $channelId): ?array
{
    $routes = $config->get('chat_routing', 'routes', []);
    if (!is_array($routes)) {
        return null;
    }

    $route = $routes[(string) $channelId] ?? null;

    return is_array($route) ? $route : null;
}
