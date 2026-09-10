<?php

declare(strict_types=1);

namespace CodexRuntime\Document;

use RuntimeException;

final class FileExchangeApiClient implements FileExchangeClientInterface
{
    public function __construct(private string $base_url, private string $token)
    {
        $this->base_url = rtrim(trim($this->base_url), '/');
        $this->token = trim($this->token);
        if ($this->base_url === '' || $this->token === '') {
            throw new RuntimeException('File exchange configuration is incomplete');
        }
    }

    public function uploadFile(string $file_path, string $filename, string $mime): array
    {
        $ch = curl_init($this->base_url . '/up');
        if ($ch === false) {
            throw new RuntimeException('Cannot initialize file exchange upload');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => [
                'file' => new \CURLFile($file_path, $mime, $filename),
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
        ]);

        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('File exchange upload failed: ' . $error);
        }

        $status_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status_code < 200 || $status_code >= 300) {
            throw new RuntimeException("File exchange upload failed with HTTP {$status_code}");
        }

        $result = json_decode($raw, true);
        if (!is_array($result)) {
            throw new RuntimeException('File exchange upload returned invalid JSON');
        }

        return $result;
    }
}
