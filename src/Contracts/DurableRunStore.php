<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\BranchWaitPayload;
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

    public function waitForBranches(string $runId, BranchWaitPayload $payload): void;

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

    /**
     * @param  array<string, mixed>  $policy
     */
    public function scheduleBranchRetry(string $runId, string $branchId, string $executionToken, array $policy, int $attempt, ?\DateTimeInterface $nextRetryAt): void;

    public function cancelBranches(string $runId, ?string $parentNodeId = null): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recoverableBranches(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dueRetryBranches(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array;

    public function markCompleted(string $runId, string $executionToken): void;

    /**
     * @param  array{message: string, class: class-string<\Throwable>, timed_out?: bool}|null  $failure
     */
    public function markFailed(string $runId, string $executionToken, ?array $failure = null): void;

    /**
     * @param  array<string, mixed>  $policy
     */
    public function scheduleRetry(string $runId, string $executionToken, array $policy, int $attempt, ?\DateTimeInterface $nextRetryAt): void;

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
    public function dueRetries(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recoverableWaitingJoins(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array;

    public function markRecoveryDispatched(string $runId): void;

    public function updateQueueRouting(string $runId, ?string $connection, ?string $queue): void;

    /**
     * @param  array<string, bool|int|float|string|null>  $labels
     */
    public function updateLabels(string $runId, array $labels): void;

    /**
     * @return array<string, bool|int|float|string|null>
     */
    public function labels(string $runId): array;

    /**
     * @param  array<string, bool|int|float|string|null>  $labels
     * @return array<int, string>
     */
    public function runIdsForLabels(array $labels, int $limit = 50): array;

    /**
     * @param  array<string, mixed>  $details
     */
    public function updateDetails(string $runId, array $details): void;

    /**
     * @return array<string, mixed>
     */
    public function details(string $runId): array;

    /**
     * @return array<string, mixed>
     */
    public function recordSignal(string $runId, string $name, mixed $payload = null, ?string $idempotencyKey = null): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function signals(string $runId): array;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createWait(string $runId, string $name, ?string $reason = null, ?int $timeoutSeconds = null, array $metadata = []): void;

    public function releaseWaitWithSignal(string $runId, string $name, int $signalId): bool;

    /**
     * @param  array<string, mixed>  $outcome
     */
    public function releaseWaitWithOutcome(string $runId, string $name, string $status, array $outcome): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function waits(string $runId): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recoverableWaitTimeouts(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array;

    public function releaseTimedOutWait(string $runId, string $name): bool;

    /**
     * @param  array<string, mixed>  $progress
     */
    public function recordProgress(string $runId, ?string $branchId, array $progress): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function progress(string $runId): array;

    /**
     * @param  array<string, mixed>  $contextPayload
     */
    public function createChildRun(string $parentRunId, string $childRunId, string $childSwarmClass, string $waitName, array $contextPayload): void;

    /**
     * @return array<string, mixed>|null
     */
    public function childRunForChild(string $childRunId): ?array;

    public function markChildRunDispatched(string $childRunId): void;

    /**
     * @param  array<string, mixed>|null  $failure
     */
    public function updateChildRun(string $childRunId, string $status, ?string $output = null, ?array $failure = null): void;

    public function markChildTerminalEventDispatched(string $childRunId): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function undispatchedChildRuns(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parentsWaitingOnTerminalChildren(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function childRuns(string $runId): array;

    /**
     * @return array{reserved: bool, duplicate: bool, conflict: bool, in_flight: bool, record: array<string, mixed>|null}
     */
    public function reserveWebhookIdempotency(string $scope, string $idempotencyKey, string $requestHash): array;

    /**
     * @param  array<string, mixed>  $responsePayload
     */
    public function completeWebhookIdempotency(string $scope, string $idempotencyKey, string $runId, array $responsePayload): void;

    public function failWebhookIdempotency(string $scope, string $idempotencyKey): void;
}
