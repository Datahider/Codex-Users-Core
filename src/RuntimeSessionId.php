<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class RuntimeSessionId
{
    public static function compose(string $sourceInstanceId, string $localSessionId): string
    {
        $sourceInstanceId = trim($sourceInstanceId);
        $localSessionId = trim($localSessionId);

        if ($sourceInstanceId === '' || str_contains($sourceInstanceId, ':')) {
            throw new RuntimeException('Invalid source instance id for runtime session');
        }

        if ($localSessionId === '' || str_contains($localSessionId, ':')) {
            throw new RuntimeException('Invalid local session id for runtime session');
        }

        return $sourceInstanceId . ':' . $localSessionId;
    }

    /**
     * @return array{source_instance_id: string, local_session_id: string}|null
     */
    public static function split(string $runtimeSessionId): ?array
    {
        $runtimeSessionId = trim($runtimeSessionId);
        if ($runtimeSessionId === '') {
            return null;
        }

        $parts = explode(':', $runtimeSessionId, 2);
        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            return null;
        }

        return [
            'source_instance_id' => trim($parts[0]),
            'local_session_id' => trim($parts[1]),
        ];
    }
}
