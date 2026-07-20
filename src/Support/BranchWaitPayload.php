<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

final readonly class BranchWaitPayload
{
    /**
     * @param  array<string, mixed>  $routeCursor  Durable cursor shape: {entries, offset, current_node_id, ...}
     * @param  array<string, mixed>|null  $routePlan  Serialized HierarchicalRoutePlan: {start_at, nodes}
     * @param  array<int, array<string, mixed>>  $branches  Branch definitions to create
     */
    public function __construct(
        public string $executionToken,
        public int $nextStepIndex,
        public string $parentNodeId,
        public RunContext $context,
        public int $ttlSeconds,
        public array $routeCursor = [],
        public ?array $routePlan = null,
        public ?int $totalSteps = null,
        public array $branches = [],
    ) {}
}
