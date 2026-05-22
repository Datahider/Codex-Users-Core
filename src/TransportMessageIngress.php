<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\ManagerQueue\EventRepository;

final class TransportMessageIngress
{
    public function __construct(private EventRepository $events)
    {
    }

    public function enqueueUserMessage(TransportInboundMessage $message, bool $mergePending = true): string
    {
        $runtimeId = trim((string) ($message->sessionId ?? ''));
        if ($mergePending && $runtimeId !== '') {
            $mergedEventId = $this->events->mergePendingRuntimeMessage($runtimeId, $message->text);
            if ($mergedEventId !== null) {
                return $mergedEventId;
            }
        }

        return $this->events->enqueue($message->toManagerEventPayload());
    }
}
