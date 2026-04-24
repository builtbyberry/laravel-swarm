<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

class MonotonicTime
{
    public static function now(): int
    {
        return hrtime(true);
    }

    public static function elapsedMilliseconds(int $startedAt): int
    {
        return max(1, intdiv(self::now() - $startedAt + 999_999, 1_000_000));
    }
}
