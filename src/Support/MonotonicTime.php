<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

class MonotonicTime
{
    public static function now(): float
    {
        return (float) hrtime(true);
    }

    public static function elapsedMilliseconds(float $startedAt): int
    {
        $deltaNs = self::now() - $startedAt + 999_999;

        return max(1, intdiv((int) $deltaNs, 1_000_000));
    }
}
