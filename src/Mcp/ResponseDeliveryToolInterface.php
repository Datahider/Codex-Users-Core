<?php

declare(strict_types=1);

namespace CodexRuntime\Mcp;

interface ResponseDeliveryToolInterface
{
    /** @return array<string, mixed> */
    public function setResponseDelivery(string $mode, string $scope): array;
}
