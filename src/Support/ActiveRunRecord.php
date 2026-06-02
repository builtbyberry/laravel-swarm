<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

/**
 * Immutable view of the swarm run currently executing an agent, as published by
 * {@see ActiveRunContext}. Carries the run id, the swarm class-string, and the
 * live {@see RunContext} the runner is executing against.
 *
 * @internal
 */
final class ActiveRunRecord
{
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly RunContext $context,
    ) {}
}
