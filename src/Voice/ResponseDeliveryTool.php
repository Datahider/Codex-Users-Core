<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use CodexRuntime\Mcp\ResponseDeliveryToolInterface;
use RuntimeException;

final class ResponseDeliveryTool implements ResponseDeliveryToolInterface
{
    public function __construct(
        private VoiceResponseModeStore $store,
        private ?string $runtime_session_id
    ) {
    }

    public function setResponseDelivery(string $mode, string $scope): array
    {
        $runtime_session_id = trim((string) $this->runtime_session_id);
        if ($runtime_session_id === '') {
            throw new RuntimeException('RUNTIME_SID is required');
        }
        $this->store->set($runtime_session_id, $mode, $scope);

        return ['mode' => strtolower(trim($mode)), 'scope' => strtolower(trim($scope))];
    }
}
