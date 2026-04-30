<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\QueuedRunAcquisition;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

interface ClaimsQueuedRunExecution
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function acquireQueuedRun(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, int $ttlSeconds, int $leaseSeconds): QueuedRunAcquisition;

    /**
     * Re-acquire the queued run lease after a coordinated hierarchical parallel join (history row is `waiting`).
     */
    public function acquireQueuedRunContinuationLease(string $runId, int $ttlSeconds, int $leaseSeconds): QueuedRunAcquisition;
}
