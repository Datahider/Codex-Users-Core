<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

use RuntimeException;

final class RouterTransportEnrollmentService implements TransportEnrollmentServiceInterface
{
    public function __construct(private ApiClient $api)
    {
    }

    public function createOnecCode(): array
    {
        $response = $this->api->postJson('/api/v1/core/transport-enrollment-code', [
            'transport_type' => 'onec',
        ]);
        $enrollment_code = $response['enrollment_code'] ?? null;
        $expires_at = $response['expires_at'] ?? null;
        if (($response['accepted'] ?? null) !== true || !is_string($enrollment_code) || !is_string($expires_at)) {
            throw new RuntimeException('Router returned invalid transport enrollment response');
        }

        return [
            'enrollment_code' => $enrollment_code,
            'expires_at' => $expires_at,
        ];
    }
}
