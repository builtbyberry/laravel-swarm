<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/** Optional run-scoped capture for step evidence. */
interface RecordsCitationSteps
{
    public function recordStepWithContext(string $runId, SwarmStep $step, int $ttlSeconds, ?string $executionToken, ?int $leaseSeconds, RunContext $context): void;
}
