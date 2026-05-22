<?php

declare(strict_types=1);

namespace CodexRuntime\Contracts;

use CodexRuntime\InboundMessage;

interface IngressGatewayInterface
{
    /**
     * @return array{accepted: bool, event_id: int|string|null, action_text: ?string}
     */
    public function submitMessage(InboundMessage $message): array;
}
