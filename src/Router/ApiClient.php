<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use RuntimeException;

final class ApiClient
{
    public function __construct(
        private string $baseUrl,
        private string $token,
        private HttpClientInterface $http
    ) {
        $this->baseUrl = rtrim(trim($this->baseUrl), '/');
        $this->token = trim($this->token);

        if ($this->baseUrl === '') {
            throw new RuntimeException('Router base URL cannot be empty');
        }

        if ($this->token === '') {
            throw new RuntimeException('Router token cannot be empty');
        }
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        $queryString = http_build_query(array_filter($query, static fn (mixed $value): bool => $value !== null));
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        return $this->decodeJsonResponse(
            $this->http->request('GET', $url, $this->headers(), null),
            'GET',
            $path
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException("Cannot encode Router request body for {$path}");
        }

        return $this->decodeJsonResponse(
            $this->http->request('POST', $this->baseUrl . $path, $this->headers(), $body),
            'POST',
            $path
        );
    }

    /**
     * @param array{status_code: int, body: string} $response
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(array $response, string $method, string $path): array
    {
        $statusCode = (int) ($response['status_code'] ?? 0);
        $rawBody = (string) ($response['body'] ?? '');
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Router {$method} {$path} returned invalid JSON");
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = trim((string) ($decoded['error'] ?? $decoded['message'] ?? ''));
            if ($message === '') {
                $message = "Router {$method} {$path} failed with HTTP {$statusCode}";
            }

            throw new RuntimeException($message);
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
        ];
    }
}
