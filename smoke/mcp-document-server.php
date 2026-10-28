#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Mcp\DocumentToolInterface;
use CodexRuntime\Mcp\CoreEnvironmentToolInterface;
use CodexRuntime\Mcp\ImageToolInterface;
use CodexRuntime\Mcp\StdioServer;
use CodexRuntime\Mcp\ResponseDeliveryToolInterface;

require_once __DIR__ . '/../src/bootstrap.php';

$tool = new class implements DocumentToolInterface, ImageToolInterface {
    public array $calls = [];

    public function sendDocument(string $path, string $caption = ''): array
    {
        $this->calls[] = [$path, $caption];

        return ['delivered' => true, 'filename' => basename($path)];
    }

    public function sendImage(string $path, string $caption = ''): array
    {
        $this->calls[] = [$path, $caption];

        return ['delivered' => true, 'filename' => basename($path)];
    }
};
$response_delivery = new class implements ResponseDeliveryToolInterface {
    public function setResponseDelivery(string $mode, string $scope): array
    {
        throw new RuntimeException('Unexpected response delivery call');
    }
};
$core_environment = new class implements CoreEnvironmentToolInterface {
    public function describe(): array
    {
        return [
            'description' => "# Core environment\n\nAuthoritative runtime description.",
            'runtime' => ['name' => 'CodexGate Core'],
        ];
    }
};

$input = fopen('php://temp', 'r+');
$output = fopen('php://temp', 'r+');
fwrite($input, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]) . "\n");
fwrite($input, json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]) . "\n");
fwrite($input, json_encode([
    'jsonrpc' => '2.0',
    'id' => 3,
    'method' => 'tools/call',
    'params' => ['name' => 'send_document', 'arguments' => ['path' => '/tmp/report.pdf', 'caption' => 'Report']],
]) . "\n");
fwrite($input, json_encode([
    'jsonrpc' => '2.0',
    'id' => 4,
    'method' => 'tools/call',
    'params' => ['name' => 'send_image', 'arguments' => ['path' => '/tmp/pixel.png', 'caption' => 'Pixel']],
]) . "\n");
fwrite($input, json_encode([
    'jsonrpc' => '2.0',
    'id' => 5,
    'method' => 'tools/call',
    'params' => ['name' => 'get_core_environment', 'arguments' => []],
]) . "\n");
rewind($input);

(new StdioServer($tool, $tool, $response_delivery, $core_environment))->run($input, $output);
rewind($output);
$responses = array_map(static fn (string $line): array => json_decode($line, true), array_values(array_filter(array_map('trim', explode("\n", stream_get_contents($output))))));

assertSame('2025-06-18', $responses[0]['result']['protocolVersion'] ?? null, 'protocol version');
assertContains('однопользовательского CodexGate Core', $responses[0]['result']['instructions'] ?? '', 'server environment instructions');
assertContains('get_core_environment', $responses[0]['result']['instructions'] ?? '', 'server environment tool instruction');
assertContains('shim', $responses[0]['result']['instructions'] ?? '', 'server shim instruction');
assertSame('get_core_environment', $responses[1]['result']['tools'][0]['name'] ?? null, 'environment tool name');
assertContains('Эта сессия работает внутри однопользовательского CodexGate Core.', $responses[1]['result']['tools'][0]['description'] ?? '', 'environment description starts with runtime identity');
assertContains('штатный shim', $responses[1]['result']['tools'][0]['description'] ?? '', 'environment description explains shims');
assertSame(true, $responses[1]['result']['tools'][0]['annotations']['readOnlyHint'] ?? null, 'environment read-only annotation');
assertSame(false, $responses[1]['result']['tools'][0]['annotations']['destructiveHint'] ?? null, 'environment non-destructive annotation');
assertSame('object', $responses[1]['result']['tools'][0]['inputSchema']['type'] ?? null, 'environment schema type');
assertSame([], $responses[1]['result']['tools'][0]['inputSchema']['required'] ?? null, 'environment schema required');
assertSame(false, $responses[1]['result']['tools'][0]['inputSchema']['additionalProperties'] ?? null, 'environment schema closed');
assertSame('send_document', $responses[1]['result']['tools'][1]['name'] ?? null, 'tool name');
assertSame('send_image', $responses[1]['result']['tools'][2]['name'] ?? null, 'image tool name');
assertSame('set_response_delivery', $responses[1]['result']['tools'][3]['name'] ?? null, 'response delivery tool name');
assertSame(
    'Required when delivering any local file to the user except a JPEG, PNG or WEBP image. Do not replace delivery with a local path or Markdown link.',
    $responses[1]['result']['tools'][1]['description'] ?? null,
    'document-only description'
);
assertSame(
    'Required when delivering a local JPEG, PNG or WEBP image to the user. Do not replace delivery with a local path or Markdown link.',
    $responses[1]['result']['tools'][2]['description'] ?? null,
    'image-only description'
);
assertSame(
    'Switch final response delivery between text and voice. scope=once applies only to the current final response; scope=persistent applies to the current and following responses.',
    $responses[1]['result']['tools'][3]['description'] ?? null,
    'response-delivery-only description'
);
assertSame('object', $responses[1]['result']['tools'][1]['inputSchema']['type'] ?? null, 'tool schema');
assertSame([['/tmp/report.pdf', 'Report'], ['/tmp/pixel.png', 'Pixel']], $tool->calls, 'tool calls');
assertSame(false, $responses[2]['result']['isError'] ?? null, 'tool result');
assertSame(false, $responses[3]['result']['isError'] ?? null, 'image tool result');
assertSame(false, $responses[4]['result']['isError'] ?? null, 'environment tool result');
assertContains('Authoritative runtime description.', $responses[4]['result']['content'][0]['text'] ?? '', 'environment human-readable content');
assertSame('CodexGate Core', $responses[4]['result']['structuredContent']['runtime']['name'] ?? null, 'environment structured content');

fwrite(STDOUT, "MCP document server smoke: OK\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $actual, string $label): void
{
    if (!str_contains($actual, $needle)) {
        throw new RuntimeException("Assertion failed for {$label}: missing " . var_export($needle, true));
    }
}
