<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use Illuminate\Support\Carbon;

/**
 * @internal
 */
final class DatabaseTtl
{
    public static function expiresAt(int $ttlSeconds): Carbon
    {
        return Carbon::now('UTC')->addSeconds($ttlSeconds);
    }
}
