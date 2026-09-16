#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\Voice\VoiceFinalPlanner;
use CodexRuntime\Voice\VoiceResponseModeStore;
use CodexRuntime\Voice\VoiceSuitabilityClassifierInterface;
use CodexRuntime\Mcp\ResponseDeliveryToolInterface;
use CodexRuntime\Mcp\StdioServer;
use CodexRuntime\Mcp\DocumentToolInterface;
use CodexRuntime\Mcp\ImageToolInterface;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    assertContractType(VoiceSuitabilityClassifierInterface::class, true);
    assertContractType(VoiceResponseModeStore::class);
    assertContractType(VoiceFinalPlanner::class);
    assertContractType(ResponseDeliveryToolInterface::class, true);

    $tmp_root = sys_get_temp_dir() . '/codex-voice-final-' . bin2hex(random_bytes(4));
    if (!mkdir($tmp_root, 0775, true) && !is_dir($tmp_root)) {
        throw new RuntimeException('Cannot create voice final test directory');
    }

    $classifier = new class implements VoiceSuitabilityClassifierInterface {
        /** @var list<array{voice: bool, reason?: string}> */
        public array $decisions = [];
        /** @var list<string> */
        public array $texts = [];

        public function classify(string $text): array
        {
            $this->texts[] = $text;
            if ($this->decisions === []) {
                throw new RuntimeException('Missing classifier decision');
            }

            return array_shift($this->decisions);
        }
    };

    $config = new Config([
        'voice_response' => [
            'max_characters' => 700,
            'classifier_model' => 'gpt-5.6-luna',
        ],
    ]);
    $store = new VoiceResponseModeStore($tmp_root . '/response-modes.json');
    $planner = new VoiceFinalPlanner($config, $store, $classifier);

    $text_plan = $planner->plan('session-default', 'final', 'Обычный ответ.');
    assertSame('final', $text_plan['kind'] ?? null, 'default final kind');
    assertSame('Обычный ответ.', $text_plan['text'] ?? null, 'default final text');
    assertSame([], $classifier->texts, 'classifier skipped in text mode');

    $store->set('session-once', 'voice', 'once');
    $commentary_plan = $planner->plan('session-once', 'commentary', 'Проверяю.');
    assertSame('commentary', $commentary_plan['kind'] ?? null, 'commentary remains text');
    assertSame([], $classifier->texts, 'commentary skips classifier');

    $classifier->decisions[] = ['voice' => true];
    $voice_once = $planner->plan('session-once', 'final', 'Краткий ответ.');
    assertSame('voice', $voice_once['kind'] ?? null, 'one-shot voice final');
    assertSame('Краткий ответ.', $voice_once['text'] ?? null, 'voice synthesis source');
    $after_once = $planner->plan('session-once', 'final', 'Следующий ответ.');
    assertSame('final', $after_once['kind'] ?? null, 'one-shot mode consumed by final');

    $store->set('session-persistent', 'voice', 'persistent');
    $code = "Вот код:\n\n```php\necho 'x';\n```";
    $code_plan = $planner->plan('session-persistent', 'final', $code);
    assertSame('final', $code_plan['kind'] ?? null, 'code block forces text');
    assertSame(
        "Отправляю текстом: ответ содержит блок кода.\n\n{$code}",
        $code_plan['text'] ?? null,
        'code block explanation'
    );
    assertSame(['Краткий ответ.'], $classifier->texts, 'code block skips classifier');

    $long_text = str_repeat('x', 701);
    $long_plan = $planner->plan('session-persistent', 'final', $long_text);
    assertSame('final', $long_plan['kind'] ?? null, 'over-limit final kind');
    assertSame(
        "Отправляю текстом: ответ слишком длинный для голосового сообщения.\n\n{$long_text}",
        $long_plan['text'] ?? null,
        'length explanation'
    );
    assertSame(1, count($classifier->texts), 'over-limit final skips classifier');

    $boundary_text = str_repeat('x', 700);
    $classifier->decisions[] = ['voice' => true];
    $boundary_plan = $planner->plan('session-persistent', 'final', $boundary_text);
    assertSame('voice', $boundary_plan['kind'] ?? null, 'limit is inclusive');
    assertSame($boundary_text, $classifier->texts[1] ?? null, 'boundary final reaches classifier');

    $classifier->decisions[] = [
        'voice' => false,
        'reason' => 'структура теряется без экрана',
    ];
    $rejected_plan = $planner->plan('session-persistent', 'final', 'Пункты со сложными связями.');
    assertSame('final', $rejected_plan['kind'] ?? null, 'classifier rejection kind');
    assertSame(
        "Отправляю текстом: структура теряется без экрана.\n\nПункты со сложными связями.",
        $rejected_plan['text'] ?? null,
        'classifier rejection explanation'
    );

    $store->set('session-once-rejected', 'voice', 'once');
    $rejected_once = $planner->plan('session-once-rejected', 'final', $code);
    assertSame('final', $rejected_once['kind'] ?? null, 'rejected one-shot final');
    $after_rejected_once = $planner->plan('session-once-rejected', 'final', 'Ещё ответ.');
    assertSame('final', $after_rejected_once['kind'] ?? null, 'rejected one-shot consumed');

    $store->set('session-persistent', 'text', 'persistent');
    $disabled_plan = $planner->plan('session-persistent', 'final', 'Текстовый режим.');
    assertSame('final', $disabled_plan['kind'] ?? null, 'persistent text disables voice');

    assertThrows(
        static fn () => $store->set('session-invalid', 'audio', 'persistent'),
        'Invalid response mode'
    );
    assertThrows(
        static fn () => $store->set('session-invalid', 'voice', 'forever'),
        'Invalid response mode scope'
    );

    $response_delivery_tool = new class implements ResponseDeliveryToolInterface {
        /** @var list<array{mode:string, scope:string}> */
        public array $calls = [];

        public function setResponseDelivery(string $mode, string $scope): array
        {
            $this->calls[] = ['mode' => $mode, 'scope' => $scope];

            return ['mode' => $mode, 'scope' => $scope];
        }
    };
    $document_tool = new class implements DocumentToolInterface {
        public function sendDocument(string $path, string $caption = ''): array
        {
            throw new RuntimeException('Unexpected document tool call');
        }
    };
    $image_tool = new class implements ImageToolInterface {
        public function sendImage(string $path, string $caption = ''): array
        {
            throw new RuntimeException('Unexpected image tool call');
        }
    };
    $server = new StdioServer($document_tool, $image_tool, $response_delivery_tool);
    $input = fopen('php://temp', 'r+');
    $output = fopen('php://temp', 'r+');
    if ($input === false || $output === false) {
        throw new RuntimeException('Cannot open MCP test streams');
    }
    fwrite($input, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']) . "\n");
    fwrite($input, json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => [
            'name' => 'set_response_delivery',
            'arguments' => ['mode' => 'voice', 'scope' => 'once'],
        ],
    ]) . "\n");
    rewind($input);
    $server->run($input, $output);
    rewind($output);
    $responses = [];
    while (($line = fgets($output)) !== false) {
        $responses[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }
    $listed_tools = $responses[0]['result']['tools'] ?? [];
    $listed_names = array_column($listed_tools, 'name');
    assertSame(true, in_array('set_response_delivery', $listed_names, true), 'response delivery MCP tool listed');
    assertSame([['mode' => 'voice', 'scope' => 'once']], $response_delivery_tool->calls, 'response delivery MCP call');
    assertSame(false, (bool) ($responses[1]['result']['isError'] ?? true), 'response delivery MCP result');

    fwrite(STDOUT, "Voice final planning smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Voice final planning smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertContractType(string $name, bool $interface = false): void
{
    $exists = $interface ? interface_exists($name) : class_exists($name);
    if (!$exists) {
        throw new RuntimeException('Missing contract type ' . $name);
    }
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

        throw new RuntimeException("Expected error containing '{$expected_message}', got '{$e->getMessage()}'");
    }

    throw new RuntimeException("Expected error containing '{$expected_message}'");
}
