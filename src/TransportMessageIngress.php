<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\TransportIngressGatewayInterface;

final class TransportMessageIngress
{
    public function __construct(private TransportIngressGatewayInterface $gateway)
    {
    }

    /**
     * @return array{accepted: bool, event_id: int|string|null, action_text: ?string}
     */
    public function enqueueUserMessage(TransportInboundMessage $message, bool $mergePending = true): array
    {
        return $this->gateway->submitMessage($message);
    }
}
