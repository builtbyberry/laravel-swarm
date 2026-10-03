<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures;

use Closure;
use RuntimeException;

final class ParallelStreamBootstrapFailureWorker
{
    /** @return Closure(string, string, float, int): array<string, mixed> */
    public static function closure(): Closure
    {
        return static fn (string $endpoint, string $token, float $deadline, int $maxFrameBytes): array => self::run(
            $endpoint,
            $token,
            $deadline,
            $maxFrameBytes,
        );
    }

    /** @return array<string, mixed> */
    public static function run(string $endpoint, string $token, float $deadline, int $maxFrameBytes): array
    {
        throw new RuntimeException("provider-free bootstrap exploded\0".str_repeat('SENSITIVE-DIAGNOSTIC-', 100).'TAIL-MARKER');
    }
}
