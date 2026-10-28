<?php

declare(strict_types=1);

namespace CodexRuntime\Mcp;

use RuntimeException;
use Throwable;

final class StdioServer
{
    private const ENVIRONMENT_TOOL_DESCRIPTION = 'Эта сессия работает внутри однопользовательского CodexGate Core. Сообщения приходят через внешний transport по единому протоколу; конкретный канал и исходная форма сообщения неизвестны, если не переданы явно. PATH содержит shim-команды Core: попадание команды в штатный shim, очередь или фон является ожидаемым выполнением, а не неожиданной ошибкой. Вызови этот read-only инструмент перед ответом или действием, зависящим от среды, фоновых исполнителей, scheduler, managed-процессов, файловой доставки или возможностей установки.';

    private const SERVER_INSTRUCTIONS = 'Эта агентская сессия запущена внутри однопользовательского CodexGate Core. Сообщения приходят через внешний transport по единому протоколу; конкретный канал и исходная форма сообщения неизвестны, если не переданы явно. PATH содержит штатные shim-команды Core; попадание команды в shim, очередь или фон является ожидаемым успешным маршрутом и не основанием прекращать работу. Перед environment-dependent ответом или действием вызови get_core_environment. Пользователь не имеет доступа к локальной файловой системе компьютера агента: локальный путь или Markdown-ссылка не передают ему файл.';

    public function __construct(
        private DocumentToolInterface $document_tool,
        private ImageToolInterface $image_tool,
        private ResponseDeliveryToolInterface $response_delivery_tool,
        private CoreEnvironmentToolInterface $core_environment_tool,
        private ?TransportToolService $transport_tools = null
    )
    {
    }

    /** @param resource $input @param resource $output */
    public function run($input, $output): void
    {
        $this->transport_tools?->warmup();
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
                'instructions' => self::SERVER_INSTRUCTIONS,
            ];
        }

        if ($method === 'tools/list') {
            $local_tools = [[
                'name' => 'get_core_environment',
                'description' => self::ENVIRONMENT_TOOL_DESCRIPTION,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => [],
                    'additionalProperties' => false,
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                    'idempotentHint' => true,
                    'openWorldHint' => false,
                ],
            ], [
                'name' => 'send_document',
                'description' => 'Required when delivering any local file to the user except a JPEG, PNG or WEBP image. Do not replace delivery with a local path or Markdown link.',
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
                'description' => 'Required when delivering a local JPEG, PNG or WEBP image to the user. Do not replace delivery with a local path or Markdown link.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Absolute local image path'],
                        'caption' => ['type' => 'string', 'description' => 'Optional image caption'],
                    ],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'set_response_delivery',
                'description' => 'Switch final response delivery between text and voice. scope=once applies only to the current final response; scope=persistent applies to the current and following responses.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'mode' => ['type' => 'string', 'enum' => ['text', 'voice']],
                        'scope' => ['type' => 'string', 'enum' => ['once', 'persistent']],
                    ],
                    'required' => ['mode', 'scope'],
                    'additionalProperties' => false,
                ],
            ]];
            return ['tools' => array_merge($local_tools, $this->transport_tools?->warmup() ?? [])];
        }

        if ($method === 'tools/call') {
            $params = is_array($request['params'] ?? null) ? $request['params'] : [];
            $name = (string) ($params['name'] ?? '');
            if (!in_array($name, ['get_core_environment', 'send_document', 'send_image', 'set_response_delivery'], true)) {
                if ($this->transport_tools !== null) {
                    return $this->transport_tools->call($name, is_array($params['arguments'] ?? null) ? $params['arguments'] : []);
                }
                throw new RuntimeException('Unknown MCP tool');
            }
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            if ($name === 'get_core_environment') {
                if ($arguments !== []) {
                    throw new RuntimeException('get_core_environment does not accept arguments');
                }
                $result = $this->core_environment_tool->describe();
                $text = trim((string) ($result['description'] ?? ''));
                if ($text === '') {
                    throw new RuntimeException('Core environment description is empty');
                }

                return [
                    'content' => [['type' => 'text', 'text' => $text]],
                    'structuredContent' => $result,
                    'isError' => false,
                ];
            }
            if ($name === 'set_response_delivery') {
                $result = $this->response_delivery_tool->setResponseDelivery(
                    (string) ($arguments['mode'] ?? ''),
                    (string) ($arguments['scope'] ?? '')
                );

                return [
                    'content' => [['type' => 'text', 'text' => (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                    'structuredContent' => $result,
                    'isError' => false,
                ];
            }
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
