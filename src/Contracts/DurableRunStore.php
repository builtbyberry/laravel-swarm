<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

interface DurableRunStore
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): void;

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array;

    public function assertReady(): void;

    public function acquireLease(string $runId, int $expectedStepIndex, int $stepTimeoutSeconds): ?string;

    public function assertOwned(string $runId, string $executionToken): void;

    public function markRunning(string $runId, string $executionToken, int $currentStepIndex): void;

    public function releaseForNextStep(string $runId, string $executionToken, int $nextStepIndex): void;

    /**
     * @param  array<int, string>  $nodeIds
     * @return array<string, string>
     */
    public function hierarchicalNodeOutputsFor(string $runId, array $nodeIds): array;

    /**
     * @param  array<string, mixed>  $routeCursor
     * @param  array<string, mixed>|null  $routePlan
     * @param  array{node_id: string, output: string}|null  $nodeOutput
     */
    public function checkpointHierarchicalStep(
        string $runId,
        string $executionToken,
        int $nextStepIndex,
        RunContext $context,
        int $ttlSeconds,
        array $routeCursor,
        ?array $routePlan = null,
        ?array $nodeOutput = null,
        ?int $totalSteps = null,
    ): void;

    public function markCompleted(string $runId, string $executionToken): void;

    public function markFailed(string $runId, string $executionToken): void;

    public function markPaused(string $runId, string $executionToken): void;

    public function markCancelled(string $runId, string $executionToken): void;

    public function pause(string $runId): bool;

    public function resume(string $runId): bool;

    public function cancel(string $runId): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recoverable(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array;

    public function updateQueueRouting(string $runId, ?string $connection, ?string $queue): void;
}
