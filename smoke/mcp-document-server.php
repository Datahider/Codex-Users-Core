#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Mcp\DocumentToolInterface;
use CodexRuntime\Mcp\StdioServer;

require_once __DIR__ . '/../src/bootstrap.php';

$tool = new class implements DocumentToolInterface {
    public array $calls = [];

    public function sendDocument(string $path, string $caption = ''): array
    {
        $this->calls[] = [$path, $caption];

        return ['delivered' => true, 'filename' => basename($path)];
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
rewind($input);

(new StdioServer($tool))->run($input, $output);
rewind($output);
$responses = array_map(static fn (string $line): array => json_decode($line, true), array_values(array_filter(array_map('trim', explode("\n", stream_get_contents($output))))));

assertSame('2025-06-18', $responses[0]['result']['protocolVersion'] ?? null, 'protocol version');
assertSame('send_document', $responses[1]['result']['tools'][0]['name'] ?? null, 'tool name');
assertSame('object', $responses[1]['result']['tools'][0]['inputSchema']['type'] ?? null, 'tool schema');
assertSame([['/tmp/report.pdf', 'Report']], $tool->calls, 'tool call');
assertSame(false, $responses[2]['result']['isError'] ?? null, 'tool result');

fwrite(STDOUT, "MCP document server smoke: OK\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
