<?php

declare(strict_types=1);

namespace CodexRuntime\Mcp;

use RuntimeException;
use Throwable;

final class StdioServer
{
    public function __construct(
        private DocumentToolInterface $document_tool,
        private ImageToolInterface $image_tool
    )
    {
    }

    /** @param resource $input @param resource $output */
    public function run($input, $output): void
    {
        while (($line = fgets($input)) !== false) {
            $request = json_decode(trim($line), true);
            if (!is_array($request)) {
                continue;
            }

            $id = $request['id'] ?? null;
            if ($id === null) {
                continue;
            }

            try {
                $result = $this->handle($request);
                $response = ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
            } catch (Throwable $e) {
                $response = [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => ['code' => -32603, 'message' => $e->getMessage()],
                ];
            }

            $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || fwrite($output, $json . "\n") === false) {
                throw new RuntimeException('Cannot write MCP response');
            }
            fflush($output);
        }
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function handle(array $request): array
    {
        $method = (string) ($request['method'] ?? '');
        if ($method === 'initialize') {
            return [
                'protocolVersion' => '2025-06-18',
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'codex-runtime-core', 'version' => '1.0.0'],
                'instructions' => 'Use send_document when the user asks you to deliver a local file into the current chat.',
            ];
        }

        if ($method === 'tools/list') {
            return ['tools' => [[
                'name' => 'send_document',
                'description' => 'Send a local file as a document to the current runtime chat.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Absolute local file path'],
                        'caption' => ['type' => 'string', 'description' => 'Optional document caption'],
                    ],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'send_image',
                'description' => 'Send a local JPEG, PNG or WEBP image to the current runtime chat.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Absolute local image path'],
                        'caption' => ['type' => 'string', 'description' => 'Optional image caption'],
                    ],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
            ]]];
        }

        if ($method === 'tools/call') {
            $params = is_array($request['params'] ?? null) ? $request['params'] : [];
            $name = (string) ($params['name'] ?? '');
            if (!in_array($name, ['send_document', 'send_image'], true)) {
                throw new RuntimeException('Unknown MCP tool');
            }
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $path = trim((string) ($arguments['path'] ?? ''));
            if ($path === '') {
                throw new RuntimeException('send_document path is required');
            }
            $caption = (string) ($arguments['caption'] ?? '');
            $result = $name === 'send_document'
                ? $this->document_tool->sendDocument($path, $caption)
                : $this->image_tool->sendImage($path, $caption);

            return [
                'content' => [['type' => 'text', 'text' => (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                'structuredContent' => $result,
                'isError' => false,
            ];
        }

        throw new RuntimeException("Unsupported MCP method {$method}");
    }
}
