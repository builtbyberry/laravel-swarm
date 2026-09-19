<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use RuntimeException;

class WakesWithFailure
{
    public function __wakeup(): void
    {
        throw new RuntimeException('tool-result-wakeup');
    }
}
