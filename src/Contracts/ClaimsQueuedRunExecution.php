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
     * Re-acquire the queued run lease after a coordinated hierarchical parallel join (history row is `waiting`),
     * or reclaim it from a continuation whose worker died (history row `running` with an expired lease).
     *
     * Either way the lease is taken only while the run's durable coordination row is `pending` — released to
     * its join. Any other durable state (still `waiting` at a boundary, paused, terminal) means a stray or
     * duplicate resume: return a non-acquired outcome and leave the history row untouched, so the resume for
     * the actual release can still take it. Read the durable row with a lock, before the history row.
     */
    public function acquireQueuedRunContinuationLease(string $runId, int $ttlSeconds, int $leaseSeconds): QueuedRunAcquisition;
}
