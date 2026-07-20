<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * @internal
 */
readonly class SwarmExecutionState
{
    public function __construct(
        public Swarm $swarm,
        public Topology $topology,
        public ExecutionMode $executionMode,
        public float $deadlineMonotonic,
        public int $maxAgentExecutions,
        public int $ttlSeconds,
        public ?int $leaseSeconds,
        public ?string $executionToken,
        public ?Closure $verifyOwnership,
        public RunContext $context,
        public ContextStore $contextStore,
        public ArtifactRepository $artifactRepository,
        public RunHistoryStore $historyStore,
        public Dispatcher $events,
        /**
         * When set to `multi_worker` for hierarchical `queue()`, parallel route nodes use
         * coordinated branch jobs instead of in-process execution.
         */
        public ?string $queueHierarchicalParallelCoordination = null,
    ) {}
}
