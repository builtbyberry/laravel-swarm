<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait;

use Closure;

final class MarkerPublicationWorker
{
    /** @return Closure(): int */
    public static function writer(string $dir, int $count): Closure
    {
        return static function () use ($dir, $count): int {
            for ($i = 0; $i < $count; $i++) {
                LockBarrier::await($dir, 'ready-'.$i, 5.0);
                LockBarrier::signal($dir, 'pid-'.$i, (string) (1000 + $i));
                LockBarrier::await($dir, 'ack-'.$i, 5.0);
                foreach (['ready-', 'pid-', 'ack-'] as $prefix) {
                    unlink($dir.'/'.$prefix.$i);
                }
            }

            return $count;
        };
    }

    /** @return Closure(): array<int, array{expected: string, actual: string}> */
    public static function reader(string $dir, int $count): Closure
    {
        return static function () use ($dir, $count): array {
            $mismatches = [];
            for ($i = 0; $i < $count; $i++) {
                LockBarrier::signal($dir, 'ready-'.$i);
                $value = LockBarrier::await($dir, 'pid-'.$i, 5.0);
                $expected = (string) (1000 + $i);
                if (! ctype_digit($value) || $value !== $expected) {
                    $mismatches[] = ['expected' => $expected, 'actual' => $value];
                }
                LockBarrier::signal($dir, 'ack-'.$i);
            }

            return $mismatches;
        };
    }
}
