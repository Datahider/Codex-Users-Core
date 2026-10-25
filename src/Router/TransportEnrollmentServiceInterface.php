<?php

declare(strict_types=1);

namespace CodexRuntime\Router;

interface TransportEnrollmentServiceInterface
{
    /** @return array{enrollment_code:string,expires_at:string} */
    public function createCode(): array;
}
