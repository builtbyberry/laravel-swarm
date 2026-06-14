<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\ReplaySwarmMemory;

/**
 * View of the swarm run currently executing an agent, as published by
 * {@see ActiveRunContext}. Carries the run id, the swarm class-string, and the
 * live {@see RunContext} the runner is executing against.
 *
 * The optional {@see $memoryOverride} carries a per-invocation frozen
 * {@see SwarmMemory} view (a {@see ReplaySwarmMemory})
 * during a crash-resume replay. It is process-local and scoped to this frame
 * rather than rebound on the container, so concurrent in-process streams (under
 * Octane fibers / request pooling) cannot read each other's frozen view. It is
 * mutable so {@see MemoryReplayCoordinator} can set/clear it on the active frame
 * across a generator's consumer-paced yields.
 *
 * @internal
 */
final class ActiveRunRecord
{
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly RunContext $context,
        public ?SwarmMemory $memoryOverride = null,
    ) {}
}
