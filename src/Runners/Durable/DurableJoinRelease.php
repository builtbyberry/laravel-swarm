<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Runners\Durable\Concerns\RunsAfterOutermostCommit;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * Releases a run waiting at a branch boundary to its join: for the last branch to
 * finish, for resume, and for a wait closer (signal, wait timeout, child outcome)
 * once it has committed. swarm:recover's waiting-join sweep is the one other path:
 * it releases through the same store call but dispatches the join itself
 * ({@see DurableRecoveryCoordinator::recover()}).
 *
 * The release itself is {@see DurableRunStore::releaseWaitingRunForJoin()}, which
 * refuses while the run has an open wait. A boundary whose `fail_run` policy already
 * has a failed branch is never released here: that parent is failed — by the
 * failing branch's worker, or by swarm:recover if that worker never got there — and
 * a join must not run first.
 *
 * @internal
 */
class DurableJoinRelease
{
    use RunsAfterOutermostCommit;
    use SafeReporting;

    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DurableRunContext $runs,
        protected DurableBranchCoordinator $branches,
        protected DurableOutbox $outbox,
        protected DurableEagerDispatcher $eager,
        protected Connection $connection,
        protected Container $container,
    ) {}

    /**
     * Release the run to its join when it is waiting at a branch boundary whose
     * branches are all terminal, no wait is open, and no `fail_run` failure is owed.
     * The release and the join's dispatch commit together. Returns whether the run
     * was released.
     *
     * @param  array<string, mixed>  $run
     * @param  array<int, array<string, mixed>>|null  $branches  the boundary's branch rows, when the caller already read them
     */
    public function releaseIfReady(array $run, ?array $branches = null): bool
    {
        if (($run['status'] ?? null) !== 'waiting' || ! is_string($run['current_node_id'] ?? null)) {
            return false;
        }

        $runId = (string) $run['run_id'];
        $branches ??= $this->durableRuns->branchesFor($runId, $run['current_node_id']);

        if (! $this->branches->branchesAreTerminal($branches) || $this->branchesOweFailure($runId, $branches)) {
            return false;
        }

        return $this->connection->transaction(function () use ($run, $runId): bool {
            if (! $this->durableRuns->releaseWaitingRunForJoin($runId, (int) $run['next_step_index'])) {
                return false;
            }

            $updated = $this->runs->requireRun($runId);

            if (($run['coordination_profile'] ?? CoordinationProfile::StepDurable->value) === CoordinationProfile::QueueHierarchicalParallel->value) {
                // Queued-hierarchical resume stays relay-driven; only its branches are eager.
                $this->outbox->enqueueQueuedResume($runId, $updated['queue_connection'], $updated['queue_name']);
            } else {
                $this->eager->enqueueStep($runId, (int) $updated['next_step_index'], $updated['queue_connection'], $updated['queue_name']);
            }

            return true;
        });
    }

    /**
     * Try {@see releaseIfReady()} once the swarm connection's outermost transaction
     * has committed — immediately when none is open. A wait closer calls this so the
     * join release never runs inside the closer's own transaction: it takes the
     * run's wait rows before the run row, and the closer already holds the run row.
     * A savepoint or outer rollback discards the attempt. When the transaction
     * manager does not track every open level, nothing is attached and swarm:recover
     * releases the join. A failure is reported, never thrown: the closer's work is
     * already committed.
     */
    public function releaseAfterCommit(string $runId): void
    {
        $this->runAfterOutermostCommit(function () use ($runId): void {
            try {
                $run = $this->durableRuns->find($runId);

                if ($run !== null) {
                    $this->releaseIfReady($run);
                }
            } catch (Throwable $e) {
                $this->safeReport(new RuntimeException(
                    "Durable run [{$runId}] could not be released to its join after a wait closed; swarm:recover releases it.",
                    previous: $e,
                ));
            }
        });
    }

    /**
     * Whether the run's branch boundary carries a `fail_run` failure: a branch failed
     * under the `fail_run` policy, so the parent is to be failed, not joined.
     *
     * @param  array<string, mixed>  $run
     */
    public function owesFailure(array $run): bool
    {
        if (! is_string($run['current_node_id'] ?? null)) {
            return false;
        }

        return $this->branchesOweFailure(
            (string) $run['run_id'],
            $this->durableRuns->branchesFor((string) $run['run_id'], $run['current_node_id']),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     */
    protected function branchesOweFailure(string $runId, array $branches): bool
    {
        foreach ($branches as $branch) {
            if (($branch['status'] ?? null) === 'failed') {
                return $this->branches->parallelFailurePolicy($this->runs->loadContext($runId)) === DurableParallelFailurePolicy::FailRun;
            }
        }

        return false;
    }
}
