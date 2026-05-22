<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\IngressGatewayInterface;

final class IngressPublisher
{
    public function __construct(private IngressGatewayInterface $gateway)
    {
    }

    /**
     * @return array{accepted: bool, event_id: int|string|null, action_text: ?string}
     */
    public function enqueueUserMessage(InboundMessage $message, bool $mergePending = true): array
    {
        return $this->gateway->submitMessage($message);
    }
}
