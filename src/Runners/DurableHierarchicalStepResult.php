<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;

final readonly class DurableHierarchicalStepResult
{
    /**
     * @param  array<string, mixed>  $routeCursor
     * @param  array<string, mixed>|null  $routePlan
     * @param  array{node_id: string, output: string}|null  $nodeOutput
     * @param  array<int, array<string, mixed>>  $branches
     */
    public function __construct(
        public ?SwarmStep $step,
        public array $routeCursor,
        public ?array $routePlan = null,
        public ?array $nodeOutput = null,
        public bool $complete = false,
        public ?int $totalSteps = null,
        public array $branches = [],
        public ?string $waitingParentNodeId = null,
        public bool $joinedBranches = false,
        public ?int $nextStepIndex = null,
    ) {}
}
