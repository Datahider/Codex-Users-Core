<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use RuntimeException;

final class CurlHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("Cannot initialize cURL for {$url}");
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Router HTTP request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status_code' => $statusCode,
            'body' => $raw,
        ];
    }
}
