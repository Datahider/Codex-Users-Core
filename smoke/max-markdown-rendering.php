#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\Max\MaxOutboundConsumer;
use CodexRuntime\Max\MaxTextRenderer;
use CodexRuntime\Max\MaxTransportClient;
use CodexRuntime\Max\MaxTransportStateStore;
use CodexRuntime\OutboundQueue\MessageRepository;

require_once __DIR__ . '/../src/bootstrap.php';

$tmpRoot = sys_get_temp_dir() . '/codex-runtime-max-render-smoke-' . bin2hex(random_bytes(4));
$logPath = $tmpRoot . '/runtime.log';
@mkdir($tmpRoot, 0777, true);

try {
    $renderer = new MaxTextRenderer();

    $final = $renderer->renderFinal("# Title\n\n- **bold** item\n- plain item\n- third item\n\nVisit [site](https://example.com)");
    assertSame("✅ <b>TITLE</b>\n\n• <strong>bold</strong> item\n• plain item\n• third item\n\n\nVisit <a href=\"https://example.com\">site</a>", $final, 'final markdown rendering');

    $finalMultiline = $renderer->renderFinal("**Вариант 2**\nЭто канал...");
    assertSame("✅ <strong>Вариант 2</strong>\nЭто канал...", $finalMultiline, 'final markdown rendering preserves newline after bold line');

    $nestedList = $renderer->renderFinal("- actor бота\n- actor человека\n  - kind = direct\n  - участники ровно:\n  - других active участников нет");
    assertSame("✅ • actor бота\n• actor человека\n\n• kind = direct\n• участники ровно:\n• других active участников нет", $nestedList, 'nested list reference rendering');

    $orderedListWithStart = $renderer->renderFinal("2. second\n3. third");
    assertSame("✅ 2. second\n\n3. third", $orderedListWithStart, 'ordered list preserves start attribute numbering');
    assertFalse(str_contains($orderedListWithStart, '<ol'), 'ordered list strips raw ol html');

    $commentary = $renderer->renderCommentary("Use `php -v` and *watch logs*.");
    assertSame('<i>Use <code>php -v</code> and <i>watch logs</i>.</i>', $commentary, 'commentary markdown rendering');

    $consumer = new MaxOutboundConsumer(
        new Config([
            'transport' => [
                'outbound_poll_interval_ms' => 100,
            ],
            'storage' => [
                'log_file' => $logPath,
            ],
        ]),
        new Logger($logPath),
        instantiateWithoutConstructor(MessageRepository::class),
        instantiateWithoutConstructor(MaxTransportClient::class),
        instantiateWithoutConstructor(MaxTransportStateStore::class)
    );

    $method = new ReflectionMethod($consumer, 'renderMessage');
    $method->setAccessible(true);

    $finalMessage = $method->invoke($consumer, 'final', '**done**');
    assertSame(['✅ <strong>done</strong>', 'HTML'], $finalMessage, 'final outbound render contract');

    $commentaryMessage = $method->invoke($consumer, 'commentary', '*thinking*');
    assertSame(['<i><i>thinking</i></i>', 'HTML'], $commentaryMessage, 'commentary outbound render contract');

    fwrite(STDOUT, "MAX markdown rendering smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "MAX markdown rendering smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    deleteTree($tmpRoot);
}

/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
function instantiateWithoutConstructor(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        $expectedText = var_export($expected, true);
        $actualText = var_export($actual, true);
        throw new RuntimeException("Assertion failed for {$label}: expected {$expectedText}, got {$actualText}");
    }
}

function assertFalse(bool $actual, string $label): void
{
    if ($actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected false, got true");
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
