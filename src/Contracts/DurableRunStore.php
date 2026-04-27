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
     * @param  array<string, mixed>  $routeCursor
     * @param  array<string, mixed>|null  $routePlan
     * @param  array<int, array<string, mixed>>  $branches
     */
    public function waitForBranches(string $runId, string $executionToken, int $nextStepIndex, string $parentNodeId, RunContext $context, int $ttlSeconds, array $routeCursor = [], ?array $routePlan = null, ?int $totalSteps = null, array $branches = []): void;

    public function releaseWaitingRunForJoin(string $runId, int $nextStepIndex): bool;

    /**
     * @param  array<int, string>  $nodeIds
     * @return array<string, string>
     */
    public function hierarchicalNodeOutputsFor(string $runId, array $nodeIds): array;

    public function storeHierarchicalNodeOutput(string $runId, string $nodeId, string $output, int $ttlSeconds): void;

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

    /**
     * @param  array<int, array<string, mixed>>  $branches
     */
    public function createBranches(string $runId, array $branches, int $ttlSeconds): void;

    /**
     * @return array<string, mixed>|null
     */
    public function findBranch(string $runId, string $branchId): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function branchesFor(string $runId, ?string $parentNodeId = null): array;

    public function acquireBranchLease(string $runId, string $branchId, int $stepTimeoutSeconds): ?string;

    public function assertBranchOwned(string $runId, string $branchId, string $executionToken): void;

    public function markBranchRunning(string $runId, string $branchId, string $executionToken): void;

    /**
     * @param  array<string, int>  $usage
     */
    public function markBranchCompleted(string $runId, string $branchId, string $executionToken, string $output, array $usage, int $durationMs): void;

    /**
     * @param  array{message: string, class: class-string<\Throwable>}  $failure
     */
    public function markBranchFailed(string $runId, string $branchId, string $executionToken, array $failure): void;

    public function cancelBranches(string $runId, ?string $parentNodeId = null): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recoverableBranches(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array;

    public function markCompleted(string $runId, string $executionToken): void;

    /**
     * @param  array{message: string, class: class-string<\Throwable>, timed_out?: bool}|null  $failure
     */
    public function markFailed(string $runId, string $executionToken, ?array $failure = null): void;

    public function markPaused(string $runId, string $executionToken): void;

    public function markCancelled(string $runId, string $executionToken): void;

    public function pause(string $runId): bool;

    public function resume(string $runId): bool;

    public function cancel(string $runId): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recoverable(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recoverableWaitingJoins(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array;

    public function markRecoveryDispatched(string $runId): void;

    public function updateQueueRouting(string $runId, ?string $connection, ?string $queue): void;
}
