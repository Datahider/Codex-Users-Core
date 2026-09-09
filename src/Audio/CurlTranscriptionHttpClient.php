<?php

declare(strict_types=1);

namespace CodexRuntime\Audio;

use CURLFile;
use RuntimeException;

final class CurlTranscriptionHttpClient implements TranscriptionHttpClientInterface
{
    public function transcribe(string $url, string $api_key, string $file_path, string $model): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize OpenAI transcription request');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
            ],
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($file_path),
                'model' => $model,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
        ]);

        $raw_response = curl_exec($curl);
        if ($raw_response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('OpenAI transcription request failed: ' . $error);
        }

        $status_code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $response = json_decode($raw_response, true);
        if (!is_array($response)) {
            throw new RuntimeException('OpenAI transcription response is not valid JSON');
        }

        if ($status_code < 200 || $status_code >= 300) {
            $message = $response['error']['message'] ?? "HTTP {$status_code}";
            throw new RuntimeException('OpenAI transcription failed: ' . (string) $message);
        }

        return $response;
    }
}
