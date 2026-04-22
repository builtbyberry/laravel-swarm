<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use Illuminate\Contracts\Events\Dispatcher;

class SwarmExecutionState
{
    public function __construct(
        public readonly Swarm $swarm,
        public readonly string $topology,
        public readonly ExecutionMode $executionMode,
        public readonly float $deadlineMonotonic,
        public readonly int $maxAgentExecutions,
        public readonly int $ttlSeconds,
        public readonly RunContext $context,
        public readonly ContextStore $contextStore,
        public readonly ArtifactRepository $artifactRepository,
        public readonly RunHistoryStore $historyStore,
        public readonly Dispatcher $events,
    ) {}
}
