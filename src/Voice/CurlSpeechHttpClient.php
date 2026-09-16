<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use RuntimeException;

final class CurlSpeechHttpClient implements SpeechHttpClientInterface
{
    public function synthesize(string $url, string $api_key, array $payload): string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize OpenAI speech request');
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
        ]);
        $response = curl_exec($curl);
        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('OpenAI speech request failed: ' . $error);
        }
        $status_code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($status_code < 200 || $status_code >= 300) {
            $decoded = json_decode($response, true);
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? "HTTP {$status_code}") : "HTTP {$status_code}";
            throw new RuntimeException('OpenAI speech failed: ' . (string) $message);
        }
        if ($response === '') {
            throw new RuntimeException('OpenAI speech returned empty audio');
        }

        return $response;
    }
}
