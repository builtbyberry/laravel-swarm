<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

final class BranchWaitPayload
{
    /**
     * @param  array<string, mixed>  $routeCursor  Durable cursor shape: {entries, offset, current_node_id, ...}
     * @param  array<string, mixed>|null  $routePlan  Serialized HierarchicalRoutePlan: {start_at, nodes}
     * @param  array<int, array<string, mixed>>  $branches  Branch definitions to create
     */
    public function __construct(
        public readonly string $executionToken,
        public readonly int $nextStepIndex,
        public readonly string $parentNodeId,
        public readonly RunContext $context,
        public readonly int $ttlSeconds,
        public readonly array $routeCursor = [],
        public readonly ?array $routePlan = null,
        public readonly ?int $totalSteps = null,
        public readonly array $branches = [],
    ) {}
}
