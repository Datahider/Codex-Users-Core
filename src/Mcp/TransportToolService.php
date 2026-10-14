<?php

declare(strict_types=1);

namespace CodexRuntime\Mcp;

use CodexRuntime\Logger;
use RuntimeException;

final class TransportToolService
{
    /** @var array<int, array<string, mixed>> */
    private array $tools = [];
    private bool $warmed = false;

    /** @param list<string> $local_tool_names */
    public function __construct(
        private TransportToolStore $store,
        private ToolEventSenderInterface $sender,
        private Logger $logger,
        private string $runtime_session_id,
        private array $local_tool_names,
        private float $cache_ttl_seconds,
        private float $list_timeout_seconds,
        private float $call_timeout_seconds
    ) {
        if ($this->cache_ttl_seconds < 0 || $this->list_timeout_seconds <= 0 || $this->call_timeout_seconds <= 0) {
            throw new RuntimeException('Transport tool TTL must be non-negative and timeouts must be positive');
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function warmup(): array
    {
        if ($this->warmed) {
            return $this->tools;
        }
        $this->warmed = true;
        $cached = $this->store->readCache($this->runtime_session_id);
        if ($cached !== null) {
            return $this->tools = $cached['tools'];
        }

        $lock = $this->store->acquireListLock($this->runtime_session_id);
        try {
            $cached = $this->store->readCache($this->runtime_session_id);
            if ($cached !== null) {
                return $this->tools = $cached['tools'];
            }
            if (!$lock['leader']) {
                return $this->tools = [];
            }
            $request_id = $this->store->register($this->runtime_session_id, 'list', $this->list_timeout_seconds);
            $this->sender->sendToolEvent($this->runtime_session_id, ['type' => 'list', 'request_id' => $request_id]);
            $response = $this->store->await($request_id, $this->list_timeout_seconds);
            if ($response === null) {
                $this->logger->error('Transport tool list timed out', ['runtime_session_id' => $this->runtime_session_id]);
                return $this->tools = [];
            }
            $tools = $this->validateListResponse($response, $request_id);
            $this->store->writeCache($this->runtime_session_id, $tools, $this->cache_ttl_seconds);
            return $this->tools = $tools;
        } catch (RuntimeException $e) {
            $this->logger->error('Transport tool list rejected', [
                'runtime_session_id' => $this->runtime_session_id,
                'error' => $e->getMessage(),
            ]);
            return $this->tools = [];
        } finally {
            $this->store->releaseListLock($lock['handle']);
        }
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function call(string $name, array $arguments): array
    {
        if (!$this->hasTool($name)) {
            throw new RuntimeException('Unknown MCP tool');
        }
        $request_id = $this->store->register($this->runtime_session_id, 'call', $this->call_timeout_seconds);
        $this->sender->sendToolEvent($this->runtime_session_id, [
            'type' => 'call',
            'request_id' => $request_id,
            'name' => $name,
            'arguments' => $arguments,
        ]);
        $response = $this->store->await($request_id, $this->call_timeout_seconds);
        if ($response === null) {
            $this->logger->error('Transport tool call timed out', ['request_id' => $request_id, 'name' => $name]);
            return $this->errorResult(['code' => 'timeout', 'message' => 'Transport tool call timed out']);
        }
        $type = (string) ($response['type'] ?? '');
        if ((string) ($response['request_id'] ?? '') !== $request_id) {
            throw new RuntimeException('Transport tool response request_id mismatch');
        }
        if ($type === 'result'
            && is_array($response['result'] ?? null)
            && is_array($response['result']['content'] ?? null)
            && (!isset($response['result']['isError']) || is_bool($response['result']['isError']))) {
            return $response['result'];
        }
        if ($type === 'error' && $this->validError($response['error'] ?? null)) {
            return $this->errorResult($response['error']);
        }
        throw new RuntimeException('Invalid transport tool call response');
    }

    private function hasTool(string $name): bool
    {
        foreach ($this->tools as $tool) {
            if (($tool['name'] ?? null) === $name) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $response @return array<int, array<string, mixed>> */
    private function validateListResponse(array $response, string $request_id): array
    {
        if (($response['type'] ?? null) !== 'list' || ($response['request_id'] ?? null) !== $request_id || !is_array($response['tools'] ?? null)) {
            throw new RuntimeException('Invalid transport tool list response');
        }
        $names = [];
        foreach ($response['tools'] as $tool) {
            if (!is_array($tool)
                || trim((string) ($tool['name'] ?? '')) === ''
                || !is_string($tool['description'] ?? null)
                || !is_array($tool['inputSchema'] ?? null)) {
                throw new RuntimeException('Invalid MCP Tool in transport list');
            }
            $name = $tool['name'];
            if (in_array($name, $this->local_tool_names, true) || isset($names[$name])) {
                throw new RuntimeException("Transport tool name collision: {$name}");
            }
            $names[$name] = true;
        }
        return array_values($response['tools']);
    }

    private function validError(mixed $error): bool
    {
        return is_array($error)
            && is_string($error['code'] ?? null)
            && is_string($error['message'] ?? null);
    }

    /** @param array<string, mixed> $error @return array<string, mixed> */
    private function errorResult(array $error): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]],
            'isError' => true,
        ];
    }
}
