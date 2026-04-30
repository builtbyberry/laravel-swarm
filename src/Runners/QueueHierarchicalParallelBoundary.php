<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;

/**
 * @internal Returned when a queued hierarchical run defers parallel branch work to coordinated jobs.
 */
final readonly class QueueHierarchicalParallelBoundary
{
    /**
     * @param  array<int, array<string, mixed>>  $branchDefinitions
     * @param  array<string, mixed>  $routeCursor
     * @param  array<string, mixed>  $routePlan
     * @param  array<int, SwarmStep>  $stepsSoFar
     * @param  array<string, int>  $mergedUsage
     * @param  array<int, string>  $executedNodeIds
     * @param  array<int, string>  $executedAgentClasses
     * @param  array<int, array{node_id: string, branches: array<int, string>}>  $parallelGroups
     * @param  array<string, string>  $nodeOutputs
     */
    public function __construct(
        public string $parentParallelNodeId,
        public array $branchDefinitions,
        public array $routeCursor,
        public array $routePlan,
        public int $nextStepIndexAfterJoin,
        public int $totalSteps,
        public array $stepsSoFar,
        public array $mergedUsage,
        public array $executedNodeIds,
        public array $executedAgentClasses,
        public array $parallelGroups,
        public array $nodeOutputs,
        public string $coordinatorClass,
    ) {}
}
