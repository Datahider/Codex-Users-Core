<?php

declare(strict_types=1);

namespace CodexRuntime\Attachment;

use RuntimeException;

final class CurlFileDownloadHttpClient implements FileDownloadHttpClientInterface
{
    public function download(string $url, array $headers, string $destination): void
    {
        $handle = fopen($destination, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open attachment destination: {$destination}");
        }

        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);
            throw new RuntimeException('Cannot initialize attachment download');
        }

        $curl_headers = [];
        foreach ($headers as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
        ]);

        $success = curl_exec($curl);
        $error = curl_error($curl);
        $status_code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fclose($handle);

        if ($success === false) {
            throw new RuntimeException('Attachment download failed: ' . $error);
        }

        if ($status_code < 200 || $status_code >= 300) {
            throw new RuntimeException("Attachment download failed with HTTP {$status_code}");
        }
    }
}
