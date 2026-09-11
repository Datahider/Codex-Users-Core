#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\Contracts\RateLimitsProviderInterface;
use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\LimitMonitor;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $config = new Config([
        'limits' => [
            'primary_remaining_warning_percent' => 20,
            'secondary_remaining_warning_percent' => 10,
            'timezone' => 'Europe/Moscow',
        ],
    ]);

    $provider = new class implements RateLimitsProviderInterface {
        public int $reads = 0;

        public function read(): array
        {
            $this->reads++;

            return [
                'planType' => 'plus',
                'primary' => [
                    'usedPercent' => 85,
                    'windowDurationMins' => 300,
                    'resetsAt' => 1789128302,
                ],
                'secondary' => [
                    'usedPercent' => 89,
                    'windowDurationMins' => 10080,
                    'resetsAt' => 1789458314,
                ],
            ];
        }
    };

    $transport = new class implements TransportClientInterface {
        public array $messages = [];

        public function sendMessage(
            int|string $chatId,
            string $text,
            ?int $replyToMessageId = null,
            ?string $parseMode = null,
            bool $disableNotification = false
        ): array {
            $this->messages[] = ['kind' => $disableNotification ? 'commentary' : 'final', 'text' => $text];

            return ['message_id' => count($this->messages)];
        }

        public function sendWarning(int|string $chatId, string $text): array
        {
            $this->messages[] = ['kind' => 'warning', 'text' => $text];

            return ['message_id' => count($this->messages)];
        }

        public function sendTranscript(int|string $chatId, string $text): array
        {
            throw new RuntimeException('Unexpected transcript');
        }

        public function sendChatAction(int|string $chatId, string $action = 'typing'): void
        {
        }
    };

    $monitor = new LimitMonitor($config, $provider, $transport);
    $monitor->sendCurrentLimits('runtime-42');

    assertSame(1, $provider->reads, 'limits reads for /limits');
    assertSame('final', $transport->messages[0]['kind'] ?? null, '/limits response kind');
    assertContains('5 часов: осталось 15%', $transport->messages[0]['text'] ?? '', 'primary status');
    assertContains('7 дней: осталось 11%', $transport->messages[0]['text'] ?? '', 'secondary status');
    assertContains('Тариф: Plus', $transport->messages[0]['text'] ?? '', 'plan status');
    assertSame('warning', $transport->messages[1]['kind'] ?? null, '/limits warning kind');
    assertContains('5 часов: осталось 15%', $transport->messages[1]['text'] ?? '', 'primary warning');
    assertNotContains('7 дней', $transport->messages[1]['text'] ?? '', 'secondary threshold is strict');

    $transport->messages = [];
    $monitor->sendFinal('runtime-42', 'Готово.');

    assertSame(2, $provider->reads, 'limits reads after final');
    assertSame('final', $transport->messages[0]['kind'] ?? null, 'normal final kind');
    assertSame('warning', $transport->messages[1]['kind'] ?? null, 'automatic warning kind');

    fwrite(STDOUT, "Limits monitoring smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Limits monitoring smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("Assertion failed for {$label}: missing {$needle}");
    }
}

function assertNotContains(string $needle, string $haystack, string $label): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException("Assertion failed for {$label}: unexpected {$needle}");
    }
}
