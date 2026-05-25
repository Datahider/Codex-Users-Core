<?php

declare(strict_types=1);

namespace CodexRuntime\Telegram;

use CodexRuntime\RuntimeSessionId;

final class TelegramSessionId
{
    public static function fromChat(
        string $transportInstanceId,
        int|string $chatId,
        ?string $chatType = null,
        ?int $threadId = null
    ): string
    {
        $normalizedId = trim((string) $chatId);
        $numericId = is_numeric($normalizedId) ? (int) $normalizedId : 0;
        $type = strtolower(trim((string) $chatType));
        $isGroup = $type === 'group' || $type === 'supergroup' || $type === 'channel' || $numericId < 0;

        if ($isGroup) {
            $localSessionId = 'g' . ltrim((string) abs($numericId), '+');
            if ($threadId !== null && $threadId > 0) {
                $localSessionId .= '_t' . $threadId;
            }

            return RuntimeSessionId::compose($transportInstanceId, $localSessionId);
        }

        return RuntimeSessionId::compose($transportInstanceId, 'd' . $normalizedId);
    }

    /**
     * @return array{scope: 'dialog'|'group', chat_id: string, thread_id: ?int}|null
     */
    public static function resolve(string $runtimeSessionId, string $transportInstanceId): ?array
    {
        $parts = RuntimeSessionId::split($runtimeSessionId);
        if ($parts === null || $parts['transport_instance_id'] !== trim($transportInstanceId)) {
            return null;
        }

        $localSessionId = $parts['local_session_id'];

        if (preg_match('/^d(\d+)$/', $localSessionId, $matches) === 1) {
            return [
                'scope' => 'dialog',
                'chat_id' => $matches[1],
                'thread_id' => null,
            ];
        }

        if (preg_match('/^g(\d+)(?:_t(\d+))?$/', $localSessionId, $matches) === 1) {
            return [
                'scope' => 'group',
                'chat_id' => '-' . $matches[1],
                'thread_id' => isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null,
            ];
        }

        return null;
    }
}
