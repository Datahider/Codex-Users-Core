<?php

declare(strict_types=1);

namespace CodexRuntime;

final class TimestampId
{
    public static function next(): string
    {
        $now = microtime(true);
        $seconds = (int) $now;
        $micros = (int) round(($now - $seconds) * 1000000);
        if ($micros >= 1000000) {
            $seconds += 1;
            $micros = 0;
        }

        return date('Ymd-His', $seconds) . '-' . str_pad((string) $micros, 6, '0', STR_PAD_LEFT);
    }
}
