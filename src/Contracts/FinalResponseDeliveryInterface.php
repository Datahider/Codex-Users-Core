<?php

declare(strict_types=1);

namespace CodexRuntime\Contracts;

interface FinalResponseDeliveryInterface
{
    /** @return array<string, mixed> */
    public function send(string $runtime_session_id, string $text): array;
}
