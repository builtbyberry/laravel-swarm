<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\DurableHierarchicalStepResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentSettingsAttempt;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManager;
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
        protected NativeInputManager $nativeInputs,
        protected DurableEagerDispatcher $eager,
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
        ?NativeAgentSettingsAttempt $nativeSettingsAttempt = null,
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
        // commits, the outbox step pointing at the run is still dispatched — by the relay,
        // or with eager dispatch on, by the push in the finally block below. What it then
        // does depends on how far the boundary got before throwing:
        //   - nothing persisted: the run is still 'pending', acquireLease() accepts the job
        //     and the run advances normally (a safe retry with no data loss);
        //   - the wait or child intent already committed (persistChildIntent() commits in
        //     its own transaction before the hook that threw): the run is 'waiting', the
        //     job's lease is rejected and it is a no-op, and the wait's own release path
        //     (signal, child reconciliation, timeout recovery) owns progress.
        // A process killed outright leaves an eager entry reserved; the relay reclaims it
        // once its reservation goes stale.
        //
        // NOTE: branchesFor() is called INSIDE the closure (i.e., inside the checkpoint
        // transaction). For the initial branch-wait case, waitForBranches() creates the
        // branch rows in the same transaction — so the closure must read after that write.
        // The step advancer's exclusive lease ensures no concurrent process can modify
        // the branch list between the write and this read.
        //
        // Eager dispatch (swarm.durable.eager_dispatch): the entries are written for
        // direct dispatch inside the transaction (directFor(), decided per entry), but
        // NOT pushed from it. They are collected here and pushed only after
        // enterDurableBoundary() below has run: pushing at commit would let a fast
        // worker lease the still-pending run and execute the next step before a
        // declared wait or child dispatch is entered.
        $stepEntries = [];
        $branchEntries = [];

        $withTransaction = function () use ($run, $runId, $nextStepIndex, $hierarchicalResult, $context, $nativeSettingsAttempt, &$stepEntries, &$branchEntries): void {
            // Reset in case the surrounding transaction is retried.
            $stepEntries = [];
            $branchEntries = [];

            if ($nativeSettingsAttempt !== null) {
                $this->nativeInputs->commitConsumedMessages($context, $nativeSettingsAttempt);
            }
            if ($hierarchicalResult !== null && $hierarchicalResult->branches !== []) {
                $branches = $this->durableRuns->branchesFor($runId, $hierarchicalResult->waitingParentNodeId);

                foreach ($branches as $branch) {
                    $branchId = (string) $branch['branch_id'];
                    $connection = $branch['queue_connection'] ?? $run['queue_connection'];
                    $queue = $branch['queue_name'] ?? $run['queue_name'];
                    $direct = $this->eager->directFor($connection);
                    $entryId = $this->outbox->enqueueBranch($runId, $branchId, $connection, $queue, $direct);

                    if ($direct) {
                        $branchEntries[] = [$entryId, $branchId, $connection, $queue];
                    }
                }

                return;
            }

            $direct = $this->eager->directFor($run['queue_connection']);
            $entryId = $this->outbox->enqueueStep($runId, $nextStepIndex, $run['queue_connection'], $run['queue_name'], $direct);

            if ($direct) {
                $stepEntries[] = $entryId;
            }
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
        $enteredBoundary = false;

        try {
            $enteredBoundary = $enterDurableBoundary($run, $swarm, $context, $nextStepIndex) === true;
        } finally {
            // Pushed even when the boundary throws: the entries are born reserved, so
            // without a push they would wait a full reservation timeout for the relay.
            // If the boundary persisted before throwing, the pushed step job is a no-op
            // (see the crash edge case above). Only direct entries are collected.
            $this->pushCollected($runId, $nextStepIndex, $run, $enteredBoundary, $stepEntries, $branchEntries);
        }
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  list<int>  $stepEntries
     * @param  list<array{0: int, 1: string, 2: ?string, 3: ?string}>  $branchEntries
     */
    protected function pushCollected(string $runId, int $nextStepIndex, array $run, bool $enteredBoundary, array $stepEntries, array $branchEntries): void
    {
        foreach ($branchEntries as [$entryId, $branchId, $connection, $queue]) {
            $this->eager->branch($entryId, $runId, $branchId, $connection, $queue);
        }

        foreach ($stepEntries as $entryId) {
            if ($enteredBoundary) {
                // The run is now waiting: its step entry can only be a no-op, and every
                // way out of the boundary dispatches the continuation itself (signal
                // release and child reconciliation enqueue a fresh entry, a wait
                // timeout dispatches directly). Settle it rather than leave it for the
                // relay to reclaim and push as a no-op once its reservation goes stale.
                $this->outbox->settle($entryId, $runId);

                continue;
            }

            $this->eager->step($entryId, $runId, $nextStepIndex, $run['queue_connection'], $run['queue_name']);
        }
    }
}
