<?php

declare(strict_types=1);

namespace CodexRuntime\Max;

use CodexRuntime\RuntimeSessionId;

final class MaxSessionId
{
    public static function fromChannel(string $transportInstanceId, int|string $channelId, ?string $channelType = null): string
    {
        $normalizedId = trim((string) $channelId);
        $numericId = is_numeric($normalizedId) ? (int) $normalizedId : 0;
        $type = strtolower(trim((string) $channelType));

        $localSessionId = $type === 'chat' || $numericId < 0
            ? 'g' . ltrim((string) abs($numericId), '+')
            : 'd' . $normalizedId;

        return RuntimeSessionId::compose($transportInstanceId, $localSessionId);
    }

    /**
     * @return array{type: 'dialog'|'group', chat_id: string}|null
     */
    public static function resolve(string $runtimeSessionId, string $transportInstanceId): ?array
    {
        $parts = RuntimeSessionId::split($runtimeSessionId);
        if ($parts === null || $parts['transport_instance_id'] !== trim($transportInstanceId)) {
            return null;
        }

        $localSessionId = $parts['local_session_id'];
        if (preg_match('/^d(.+)$/', $localSessionId, $matches) === 1) {
            return [
                'type' => 'dialog',
                'chat_id' => $matches[1],
            ];
        }

        if (preg_match('/^g(\d+)$/', $localSessionId, $matches) === 1) {
            return [
                'type' => 'group',
                'chat_id' => '-' . $matches[1],
            ];
        }

        return null;
    }
}
