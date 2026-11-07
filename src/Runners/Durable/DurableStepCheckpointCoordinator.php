<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\Durable\Concerns\RunsAfterOutermostCommit;
use BuiltByBerry\LaravelSwarm\Runners\DurableHierarchicalStepResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentSettingsAttempt;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManager;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
class DurableStepCheckpointCoordinator
{
    use RunsAfterOutermostCommit;
    use SafeReporting;

    protected mixed $beforeStepCheckpointHook = null;

    protected mixed $afterStepCheckpointHook = null;

    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DurableRunRecorder $recorder,
        protected DurableHierarchicalCoordinator $hierarchical,
        protected DurableOutbox $outbox,
        protected NativeInputManager $nativeInputs,
        protected DurableEagerDispatcher $eager,
        protected DurableBranchAdmission $admission,
        protected Connection $connection,
        protected Container $container,
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
     * @param  callable(array<string, mixed>, Swarm, RunContext, int, ?Closure): bool  $enterDurableBoundary
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

        // Checkpoint and outbox writes share a transaction. At fan-out the same
        // transaction also persists the declared boundary; its effects are collected
        // below and run only after commit. Ordinary checkpoints retain their existing
        // separate boundary transaction. Collected eager jobs are pushed in finally
        // even when a post-commit notification throws; relay/recovery repairs a crash.
        $stepEntries = [];
        $branchEntries = [];
        $boundaryEffects = [];
        $enteredBoundary = false;

        // Fan-out cap (swarm.durable.parallel.max_concurrent_branches): validated here,
        // before the checkpoint writes anything, so an invalid or unsupported cap
        // rolls nothing back half-way. Branches past the cap are written HELD; the
        // join still sees every branch row, so the parent waits for all of them.
        $cap = $isBranchWait ? $this->admission->capForFanOut() : null;

        $withTransaction = function () use ($run, $runId, $nextStepIndex, $hierarchicalResult, $context, $nativeSettingsAttempt, $cap, $swarm, $isBranchWait, $enterDurableBoundary, &$stepEntries, &$branchEntries, &$boundaryEffects, &$enteredBoundary): void {
            // Reset in case the surrounding transaction is retried.
            $stepEntries = [];
            $branchEntries = [];
            $boundaryEffects = [];
            $enteredBoundary = false;

            if ($nativeSettingsAttempt !== null) {
                $this->nativeInputs->commitConsumedMessages($context, $nativeSettingsAttempt);
            }
            if ($isBranchWait) {
                // Persist the declared obligation in the fan-out transaction. The
                // collector keeps notifications and child dispatch outside its
                // retry/rollback boundary, with the child's live input intact.
                $deferEffects = static function (Closure $effect) use (&$boundaryEffects): void {
                    $boundaryEffects[] = $effect;
                };
                $enteredBoundary = $enterDurableBoundary($run, $swarm, $context, $nextStepIndex, $deferEffects) === true;
            }

            if ($hierarchicalResult !== null && $hierarchicalResult->branches !== []) {
                $branches = $this->durableRuns->branchesFor($runId, $hierarchicalResult->waitingParentNodeId);

                foreach (array_values($branches) as $index => $branch) {
                    $branchId = (string) $branch['branch_id'];
                    $connection = $branch['queue_connection'] ?? $run['queue_connection'];
                    $queue = $branch['queue_name'] ?? $run['queue_name'];

                    if ($cap !== null && $index >= $cap) {
                        $this->admission->hold($runId, $branchId, $connection, $queue);

                        continue;
                    }

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
                $this->recorder->checkpointHierarchical($runId, $token, $nextStepIndex, $context, $stepLeaseSeconds, $hierarchicalResult, $step, $withTransaction, (bool) ($run['durable_streaming'] ?? false));
            }
        } else {
            $this->recorder->checkpointSequential($runId, $token, $nextStepIndex, $context, $stepLeaseSeconds, (bool) ($run['durable_streaming'] ?? false), $withTransaction);
        }

        try {
            if (is_callable($this->afterStepCheckpointHook)) {
                ($this->afterStepCheckpointHook)($runId, $nextStepIndex);
            }

            if ($isBranchWait) {
                // Already entered atomically with fan-out. Re-entering here could
                // select a second wait or duplicate a child from the older context.
                $this->runBoundaryEffects($boundaryEffects);
            } else {
                // Ordinary checkpoints retain the independent boundary transaction:
                // a later notification failure must leave its intent recoverable.
                $enteredBoundary = $enterDurableBoundary($run, $swarm, $context, $nextStepIndex, null) === true;
            }
        } finally {
            $this->pushCollected($runId, $nextStepIndex, $run, $enteredBoundary, $stepEntries, $branchEntries);
        }
    }

    /**
     * @param  list<Closure(): void>  $effects
     */
    protected function runBoundaryEffects(array $effects): void
    {
        $run = static function () use ($effects): void {
            foreach ($effects as $effect) {
                $effect();
            }
        };

        if ($this->connection->transactionLevel() === 0) {
            $run();

            return;
        }

        // Attach only to this connection's outermost commit. A callback must not
        // throw through an application's commit and discard its later callbacks.
        // Untracked transactions leave persisted child intents for recovery.
        $this->runAfterOutermostCommit(function () use ($run): void {
            try {
                $run();
            } catch (Throwable $exception) {
                $this->safeReport(new RuntimeException('Durable boundary notification or child dispatch failed after commit; persisted intents remain recoverable.', previous: $exception));
            }
        });
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
