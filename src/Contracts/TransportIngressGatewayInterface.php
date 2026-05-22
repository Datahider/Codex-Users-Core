<?php

declare(strict_types=1);

namespace CodexRuntime\Contracts;

use CodexRuntime\TransportInboundMessage;

interface TransportIngressGatewayInterface
{
    /**
     * @return array{accepted: bool, event_id: int|string|null, action_text: ?string}
     */
    public function submitMessage(TransportInboundMessage $message): array;
}
