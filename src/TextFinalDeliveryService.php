<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\FinalResponseDeliveryInterface;

final class TextFinalDeliveryService implements FinalResponseDeliveryInterface
{
    public function __construct(private LimitMonitor $limit_monitor)
    {
    }

    public function send(string $runtime_session_id, string $text): array
    {
        return $this->limit_monitor->sendFinal($runtime_session_id, $text);
    }
}
