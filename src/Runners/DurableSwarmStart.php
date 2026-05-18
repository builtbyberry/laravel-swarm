<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;

/**
 * @internal
 */
final readonly class DurableSwarmStart
{
    public function __construct(
        public string $runId,
        public AdvanceDurableSwarm $job,
    ) {}
}
