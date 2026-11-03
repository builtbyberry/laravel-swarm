<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Support\BranchWaitPayload;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * A DurableRunStore that is not a DatabaseDurableRunStore: it delegates every
 * contract method to an inner store, so code that only takes a concrete-store
 * path behind an instanceof check sees a replacement store.
 */
final class DecoratingDurableRunStore implements DurableRunStore
{
    public function __construct(private readonly DurableRunStore $inner) {}

    public function create(array $payload): void
    {
        $this->inner->create($payload);
    }

    public function find(string $runId): ?array
    {
        return $this->inner->find($runId);
    }

    public function assertReady(): void
    {
        $this->inner->assertReady();
    }

    public function acquireLease(string $runId, int $expectedStepIndex, int $stepTimeoutSeconds): ?string
    {
        return $this->inner->acquireLease($runId, $expectedStepIndex, $stepTimeoutSeconds);
    }

    public function assertOwned(string $runId, string $executionToken): void
    {
        $this->inner->assertOwned($runId, $executionToken);
    }

    public function markRunning(string $runId, string $executionToken, int $currentStepIndex): void
    {
        $this->inner->markRunning($runId, $executionToken, $currentStepIndex);
    }

    public function releaseForNextStep(string $runId, string $executionToken, int $nextStepIndex): void
    {
        $this->inner->releaseForNextStep($runId, $executionToken, $nextStepIndex);
    }

    public function waitForBranches(string $runId, BranchWaitPayload $payload): void
    {
        $this->inner->waitForBranches($runId, $payload);
    }

    public function releaseWaitingRunForJoin(string $runId, int $nextStepIndex): bool
    {
        return $this->inner->releaseWaitingRunForJoin($runId, $nextStepIndex);
    }

    public function hierarchicalNodeOutputsFor(string $runId, array $nodeIds): array
    {
        return $this->inner->hierarchicalNodeOutputsFor($runId, $nodeIds);
    }

    public function storeHierarchicalNodeOutput(string $runId, string $nodeId, string $output, int $ttlSeconds): void
    {
        $this->inner->storeHierarchicalNodeOutput($runId, $nodeId, $output, $ttlSeconds);
    }

    public function checkpointHierarchicalStep(string $runId, string $executionToken, int $nextStepIndex, RunContext $context, int $ttlSeconds, array $routeCursor, ?array $routePlan = null, ?array $nodeOutput = null, ?int $totalSteps = null, array $clearBranchParentNodeIds = [
    ]): void
    {
        $this->inner->checkpointHierarchicalStep($runId, $executionToken, $nextStepIndex, $context, $ttlSeconds, $routeCursor, $routePlan, $nodeOutput, $totalSteps, $clearBranchParentNodeIds);
    }

    public function createBranches(string $runId, array $branches, int $ttlSeconds): void
    {
        $this->inner->createBranches($runId, $branches, $ttlSeconds);
    }

    public function findBranch(string $runId, string $branchId): ?array
    {
        return $this->inner->findBranch($runId, $branchId);
    }

    public function branchesFor(string $runId, ?string $parentNodeId = null): array
    {
        return $this->inner->branchesFor($runId, $parentNodeId);
    }

    public function acquireBranchLease(string $runId, string $branchId, int $stepTimeoutSeconds): ?string
    {
        return $this->inner->acquireBranchLease($runId, $branchId, $stepTimeoutSeconds);
    }

    public function assertBranchOwned(string $runId, string $branchId, string $executionToken): void
    {
        $this->inner->assertBranchOwned($runId, $branchId, $executionToken);
    }

    public function markBranchRunning(string $runId, string $branchId, string $executionToken): void
    {
        $this->inner->markBranchRunning($runId, $branchId, $executionToken);
    }

    public function markBranchCompleted(string $runId, string $branchId, string $executionToken, string $output, array $usage, int $durationMs): void
    {
        $this->inner->markBranchCompleted($runId, $branchId, $executionToken, $output, $usage, $durationMs);
    }

    public function markBranchFailed(string $runId, string $branchId, string $executionToken, array $failure): void
    {
        $this->inner->markBranchFailed($runId, $branchId, $executionToken, $failure);
    }

    public function scheduleBranchRetry(string $runId, string $branchId, string $executionToken, array $policy, int $attempt, ?\DateTimeInterface $nextRetryAt): void
    {
        $this->inner->scheduleBranchRetry($runId, $branchId, $executionToken, $policy, $attempt, $nextRetryAt);
    }

    public function cancelBranches(string $runId, ?string $parentNodeId = null): void
    {
        $this->inner->cancelBranches($runId, $parentNodeId);
    }

    public function recoverableBranches(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array
    {
        return $this->inner->recoverableBranches($runId, $swarmClass, $limit, $graceSeconds);
    }

    public function dueRetryBranches(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        return $this->inner->dueRetryBranches($runId, $swarmClass, $limit);
    }

    public function markCompleted(string $runId, string $executionToken): void
    {
        $this->inner->markCompleted($runId, $executionToken);
    }

    public function markFailed(string $runId, string $executionToken, ?array $failure = null): void
    {
        $this->inner->markFailed($runId, $executionToken, $failure);
    }

    public function scheduleRetry(string $runId, string $executionToken, array $policy, int $attempt, ?\DateTimeInterface $nextRetryAt): void
    {
        $this->inner->scheduleRetry($runId, $executionToken, $policy, $attempt, $nextRetryAt);
    }

    public function markPaused(string $runId, string $executionToken): void
    {
        $this->inner->markPaused($runId, $executionToken);
    }

    public function markCancelled(string $runId, string $executionToken): void
    {
        $this->inner->markCancelled($runId, $executionToken);
    }

    public function pause(string $runId): bool
    {
        return $this->inner->pause($runId);
    }

    public function resume(string $runId): bool
    {
        return $this->inner->resume($runId);
    }

    public function cancel(string $runId): bool
    {
        return $this->inner->cancel($runId);
    }

    public function recoverable(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array
    {
        return $this->inner->recoverable($runId, $swarmClass, $limit, $graceSeconds);
    }

    public function dueRetries(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        return $this->inner->dueRetries($runId, $swarmClass, $limit);
    }

    public function recoverableWaitingJoins(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array
    {
        return $this->inner->recoverableWaitingJoins($runId, $swarmClass, $limit, $graceSeconds);
    }

    public function recoverableQueuedResumes(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array
    {
        return $this->inner->recoverableQueuedResumes($runId, $swarmClass, $limit, $graceSeconds);
    }

    public function markRecoveryDispatched(string $runId): void
    {
        $this->inner->markRecoveryDispatched($runId);
    }

    public function markRetryRecoveryDispatched(string $runId): void
    {
        $this->inner->markRetryRecoveryDispatched($runId);
    }

    public function markBranchRecoveryDispatched(string $runId, string $branchId): void
    {
        $this->inner->markBranchRecoveryDispatched($runId, $branchId);
    }

    public function markBranchRetryRecoveryDispatched(string $runId, string $branchId): void
    {
        $this->inner->markBranchRetryRecoveryDispatched($runId, $branchId);
    }

    public function updateQueueRouting(string $runId, ?string $connection, ?string $queue): void
    {
        $this->inner->updateQueueRouting($runId, $connection, $queue);
    }

    public function updateLabels(string $runId, array $labels): void
    {
        $this->inner->updateLabels($runId, $labels);
    }

    public function labels(string $runId): array
    {
        return $this->inner->labels($runId);
    }

    public function runIdsForLabels(array $labels, int $limit = 50): array
    {
        return $this->inner->runIdsForLabels($labels, $limit);
    }

    public function updateDetails(string $runId, array $details): void
    {
        $this->inner->updateDetails($runId, $details);
    }

    public function details(string $runId): array
    {
        return $this->inner->details($runId);
    }

    public function recordSignal(string $runId, string $name, mixed $payload = null, ?string $idempotencyKey = null): array
    {
        return $this->inner->recordSignal($runId, $name, $payload, $idempotencyKey);
    }

    public function signals(string $runId): array
    {
        return $this->inner->signals($runId);
    }

    public function createWait(string $runId, string $name, ?string $reason = null, ?int $timeoutSeconds = null, array $metadata = [
    ]): void
    {
        $this->inner->createWait($runId, $name, $reason, $timeoutSeconds, $metadata);
    }

    public function releaseWaitWithSignal(string $runId, string $name, int $signalId): bool
    {
        return $this->inner->releaseWaitWithSignal($runId, $name, $signalId);
    }

    public function releaseWaitWithOutcome(string $runId, string $name, string $status, array $outcome): bool
    {
        return $this->inner->releaseWaitWithOutcome($runId, $name, $status, $outcome);
    }

    public function waits(string $runId): array
    {
        return $this->inner->waits($runId);
    }

    public function recoverableWaitTimeouts(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        return $this->inner->recoverableWaitTimeouts($runId, $swarmClass, $limit);
    }

    public function recoverableTimedOutWaits(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        return $this->inner->recoverableTimedOutWaits($runId, $swarmClass, $limit);
    }

    public function releaseTimedOutWait(string $runId, string $name): bool
    {
        return $this->inner->releaseTimedOutWait($runId, $name);
    }

    public function recordProgress(string $runId, ?string $branchId, array $progress): void
    {
        $this->inner->recordProgress($runId, $branchId, $progress);
    }

    public function progress(string $runId): array
    {
        return $this->inner->progress($runId);
    }

    public function createChildRun(string $parentRunId, string $childRunId, string $childSwarmClass, string $waitName, array $contextPayload): void
    {
        $this->inner->createChildRun($parentRunId, $childRunId, $childSwarmClass, $waitName, $contextPayload);
    }

    public function childRunForChild(string $childRunId): ?array
    {
        return $this->inner->childRunForChild($childRunId);
    }

    public function markChildRunDispatched(string $childRunId, ?\DateTimeInterface $dispatchedAt = null): bool
    {
        return $this->inner->markChildRunDispatched($childRunId, $dispatchedAt);
    }

    public function releaseChildRunDispatch(string $childRunId, \DateTimeInterface $dispatchedAt): bool
    {
        return $this->inner->releaseChildRunDispatch($childRunId, $dispatchedAt);
    }

    public function updateChildRun(string $childRunId, string $status, ?string $output = null, ?array $failure = null, array $fromStatuses = [
    ]): bool
    {
        return $this->inner->updateChildRun($childRunId, $status, $output, $failure, $fromStatuses);
    }

    public function markChildTerminalEventDispatched(string $childRunId): bool
    {
        return $this->inner->markChildTerminalEventDispatched($childRunId);
    }

    public function undispatchedChildRuns(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        return $this->inner->undispatchedChildRuns($runId, $swarmClass, $limit);
    }

    public function parentsWaitingOnTerminalChildren(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        return $this->inner->parentsWaitingOnTerminalChildren($runId, $swarmClass, $limit);
    }

    public function childRuns(string $runId): array
    {
        return $this->inner->childRuns($runId);
    }

    public function reserveWebhookIdempotency(string $scope, string $idempotencyKey, string $requestHash): array
    {
        return $this->inner->reserveWebhookIdempotency($scope, $idempotencyKey, $requestHash);
    }

    public function completeWebhookIdempotency(string $scope, string $idempotencyKey, string $runId, array $responsePayload): void
    {
        $this->inner->completeWebhookIdempotency($scope, $idempotencyKey, $runId, $responsePayload);
    }

    public function failWebhookIdempotency(string $scope, string $idempotencyKey): void
    {
        $this->inner->failWebhookIdempotency($scope, $idempotencyKey);
    }
}
