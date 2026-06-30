<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\DurableHierarchicalStepResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * @internal
 */
class DurableStepCheckpointCoordinator
{
    protected mixed $beforeStepCheckpointHook = null;

    protected mixed $afterStepCheckpointHook = null;

    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DurableRunRecorder $recorder,
        protected DurableHierarchicalCoordinator $hierarchical,
        protected DurableOutbox $outbox,
    ) {}

    public function afterStepCheckpointForTesting(?callable $hook): void
    {
        $this->afterStepCheckpointHook = $hook;
    }

    public function beforeStepCheckpointForTesting(?callable $hook): void
    {
        $this->beforeStepCheckpointHook = $hook;
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  callable(array<string, mixed>, Swarm, RunContext, int): bool  $enterDurableBoundary
     */
    public function checkpointAndDispatch(
        array $run,
        Swarm $swarm,
        string $token,
        RunContext $context,
        int $stepLeaseSeconds,
        int $nextStepIndex,
        ?DurableHierarchicalStepResult $hierarchicalResult,
        ?SwarmStep $step,
        callable $enterDurableBoundary,
    ): void {
        $runId = (string) $run['run_id'];
        $context->mergeMetadata([
            'completed_steps' => $nextStepIndex,
            'total_steps' => $context->metadata['total_steps'] ?? (int) $run['total_steps'],
        ]);

        if (is_callable($this->beforeStepCheckpointHook)) {
            ($this->beforeStepCheckpointHook)($runId, $nextStepIndex);
        }

        $isBranchWait = $hierarchicalResult !== null
            && $hierarchicalResult->branches !== []
            && $hierarchicalResult->waitingParentNodeId !== null;

        // Build the closure that runs INSIDE the recorder's transaction, atomically
        // with the checkpoint DB writes. This guarantees every outbox row is committed
        // in the same transaction so a crash between checkpoint and enqueue cannot
        // leave the run stranded.
        //
        // The outbox row is always written here, even when enterDurableBoundary (called
        // below, outside the transaction) will subsequently enter a boundary. In that
        // case the extra step-job is harmless: acquireLease() rejects 'waiting' runs, so
        // the dispatched job is a safe no-op.
        //
        // Crash edge case: if enterDurableBoundary throws after the checkpoint transaction
        // commits, the run stays 'pending' with an outbox step pointing at it. The relay
        // dispatches that job; acquireLease() accepts it and the run advances normally.
        // The boundary state is not persisted until persistChildIntent() commits its own
        // independent transaction, so this path is a safe retry with no data loss.
        //
        // NOTE: branchesFor() is called INSIDE the closure (i.e., inside the checkpoint
        // transaction). For the initial branch-wait case, waitForBranches() creates the
        // branch rows in the same transaction — so the closure must read after that write.
        // The step advancer's exclusive lease ensures no concurrent process can modify
        // the branch list between the write and this read.
        $withTransaction = function () use ($run, $runId, $nextStepIndex, $hierarchicalResult): void {
            if ($hierarchicalResult !== null && $hierarchicalResult->branches !== []) {
                $branches = $this->durableRuns->branchesFor($runId, $hierarchicalResult->waitingParentNodeId);

                foreach ($branches as $branch) {
                    $this->outbox->enqueueBranch(
                        $runId,
                        (string) $branch['branch_id'],
                        $branch['queue_connection'] ?? $run['queue_connection'],
                        $branch['queue_name'] ?? $run['queue_name'],
                    );
                }

                return;
            }

            $this->outbox->enqueueStep($runId, $nextStepIndex, $run['queue_connection'], $run['queue_name']);
        };

        if ($hierarchicalResult !== null) {
            if ($isBranchWait) {
                $this->hierarchical->checkpointBranchWait($run, $token, $nextStepIndex, $context, $stepLeaseSeconds, $hierarchicalResult, $withTransaction);
            } else {
                // Thread the run's pinned opt-in (#310) so checkpointHierarchical
                // seals the just-committed node's streamed events in its own txn —
                // the hierarchical twin of the sequential seal below.
                $this->recorder->checkpointHierarchical($runId, $token, $nextStepIndex, $context, $stepLeaseSeconds, $hierarchicalResult, $step, $withTransaction, (bool) ($run['durable_streaming'] ?? false));
            }
        } else {
            $this->recorder->checkpointSequential($runId, $token, $nextStepIndex, $context, $stepLeaseSeconds, (bool) ($run['durable_streaming'] ?? false), $withTransaction);
        }

        if (is_callable($this->afterStepCheckpointHook)) {
            ($this->afterStepCheckpointHook)($runId, $nextStepIndex);
        }

        // enterDurableBoundary is called AFTER the checkpoint transaction commits.
        // Boundary operations (e.g., child-intent persistence via persistChildIntent)
        // have their own transactions that must commit independently. Nesting them
        // inside the checkpoint transaction would cause a rollback of the boundary
        // writes if any subsequent code (e.g., afterChildIntentHook) throws, breaking
        // crash-recovery semantics.
        $enterDurableBoundary($run, $swarm, $context, $nextStepIndex);
    }
}
