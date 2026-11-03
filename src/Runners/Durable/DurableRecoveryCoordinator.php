<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaitTimedOut;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal
 */
class DurableRecoveryCoordinator
{
    use SafeReporting;

    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected Dispatcher $events,
        protected SwarmCapture $capture,
        protected DurableRunContext $runs,
        protected DurableJobDispatcher $jobs,
        protected DurableChildSwarmCoordinator $children,
        protected LoggerInterface $logger,
        protected DurableBranchAdmission $admission,
        protected Connection $connection,
        protected DurableJoinRelease $joinRelease,
    ) {}

    /**
     * $failParentFromBranches fails a run waiting at its join whose `fail_run` branch
     * failure was never settled (its branch worker stopped before failing the parent):
     * such a run is failed, never released to its join.
     *
     * @param  callable(array<string, mixed>, RunContext, int): void  $failParentFromBranches
     * @return array<int, string>
     */
    public function recover(?string $runId, ?string $swarmClass, int $limit, callable $dispatchStep, callable $dispatchBranch, callable $failParentFromBranches): array
    {
        $runs = $this->durableRuns->recoverable(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
            graceSeconds: (int) $this->config->get('swarm.durable.recovery.grace_seconds', 300),
        );

        foreach ($runs as $run) {
            $dispatch = $dispatchStep($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
            unset($dispatch);

            $this->durableRuns->markRecoveryDispatched($run['run_id']);
        }

        $graceSeconds = (int) $this->config->get('swarm.durable.recovery.grace_seconds', 300);

        // With the bundled persistence, branches the fan-out cap is holding look
        // pending and unleased; exclude them before the limit applies. Any other
        // persistence recovers exactly as before.
        $branches = $this->admission->supported() && $this->durableRuns instanceof DatabaseDurableRunStore
            ? $this->durableRuns->recoverableBranchesExcluding(
                fn (array $runIds): array => $this->admission->heldBranchIdsFor($runIds),
                runId: $runId,
                swarmClass: $swarmClass,
                limit: $limit,
                graceSeconds: $graceSeconds,
            )
            : $this->durableRuns->recoverableBranches(
                runId: $runId,
                swarmClass: $swarmClass,
                limit: $limit,
                graceSeconds: $graceSeconds,
            );

        foreach ($branches as $branch) {
            $dispatch = $dispatchBranch($branch['run_id'], $branch['branch_id'], $branch['queue_connection'], $branch['queue_name']);
            unset($dispatch);
            $this->durableRuns->markBranchRecoveryDispatched($branch['run_id'], $branch['branch_id']);
        }

        $dueRetryRuns = $this->durableRuns->dueRetries(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($dueRetryRuns as $run) {
            $dispatch = $dispatchStep($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
            unset($dispatch);
            $this->durableRuns->markRetryRecoveryDispatched($run['run_id']);
        }

        $dueRetryBranches = $this->durableRuns->dueRetryBranches(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($dueRetryBranches as $branch) {
            $dispatch = $dispatchBranch($branch['run_id'], $branch['branch_id'], $branch['queue_connection'], $branch['queue_name']);
            unset($dispatch);
            $this->durableRuns->markBranchRetryRecoveryDispatched($branch['run_id'], $branch['branch_id']);
        }

        $waitingJoins = $this->durableRuns->recoverableWaitingJoins(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
            graceSeconds: (int) $this->config->get('swarm.durable.recovery.grace_seconds', 300),
        );

        // Runs failed here, not released to a join: kept out of the redispatched set.
        $failedJoinRunIds = [];

        foreach ($waitingJoins as $run) {
            // One run whose context cannot be read (missing, or undecryptable after an
            // APP_KEY rotation) or whose failure write throws must not stop the sweep:
            // it is reported and skipped, and every other run and phase still recovers.
            try {
                $owesFailure = $this->joinRelease->owesFailure($run);

                if ($owesFailure) {
                    $failParentFromBranches(
                        $run,
                        $this->runs->loadContext($run['run_id']),
                        $this->runs->validateStepTimeoutSeconds((int) $run['step_timeout_seconds']),
                    );
                    $this->durableRuns->markRecoveryDispatched($run['run_id']);
                    $failedJoinRunIds[] = (string) $run['run_id'];
                    $this->safeLog($this->logger, 'warning', 'laravel-swarm: recovery failed a durable parent whose fail_run branch failure was never settled; it was not released to its join.', [
                        'run_id' => $run['run_id'],
                        'swarm_class' => $run['swarm_class'] ?? null,
                        'current_node_id' => $run['current_node_id'] ?? null,
                    ]);
                }
            } catch (Throwable $exception) {
                $this->safeLog($this->logger, 'warning', 'laravel-swarm: skipping a durable waiting join during recovery; it is retried on the next sweep.', [
                    'run_id' => $run['run_id'],
                    'swarm_class' => $run['swarm_class'] ?? null,
                    'current_node_id' => $run['current_node_id'] ?? null,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $failedJoinRunIds[] = (string) $run['run_id'];

                continue;
            }

            if ($owesFailure) {
                continue;
            }

            if ($this->durableRuns->releaseWaitingRunForJoin($run['run_id'], (int) $run['next_step_index'])) {
                if (($run['coordination_profile'] ?? CoordinationProfile::StepDurable->value) === CoordinationProfile::QueueHierarchicalParallel->value) {
                    $this->jobs->dispatchQueuedHierarchicalResume($run);
                } else {
                    $dispatch = $dispatchStep($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
                    unset($dispatch);
                }

                $this->durableRuns->markRecoveryDispatched($run['run_id']);
            }
        }

        $queuedResumes = $this->durableRuns->recoverableQueuedResumes(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
            graceSeconds: (int) $this->config->get('swarm.durable.recovery.grace_seconds', 300),
        );

        foreach ($queuedResumes as $run) {
            $this->jobs->dispatchQueuedHierarchicalResume($run);
            $this->durableRuns->markRecoveryDispatched($run['run_id']);
        }

        $timedOutWaits = $this->durableRuns->recoverableTimedOutWaits(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($timedOutWaits as $wait) {
            $waitName = (string) $wait['wait_name'];

            // Closing the wait and publishing its outcome commit together: a join released
            // in between could start a continuation whose history lease this run's history
            // write would then clobber.
            $updated = $this->connection->transaction(function () use ($wait, $waitName): ?array {
                if (! $this->durableRuns->releaseTimedOutWait($wait['run_id'], $waitName)) {
                    return null;
                }

                $updated = $this->runs->requireRun($wait['run_id']);
                $context = $this->runs->loadContext($wait['run_id']);
                $outcomes = is_array($context->metadata['durable_wait_outcomes'] ?? null) ? $context->metadata['durable_wait_outcomes'] : [];
                $outcomes[$waitName] = ['status' => 'timed_out', 'payload' => null, 'timed_out' => true];
                $context->mergeMetadata(['durable_wait_outcomes' => $outcomes]);
                $this->contextStore->put($this->capture->activeContext($context), $this->runs->ttlSeconds());
                $this->historyStore->syncDurableState($wait['run_id'], $updated['status'], $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false);

                return $updated;
            });

            if ($updated === null) {
                continue;
            }

            $this->events->dispatch(new SwarmWaitTimedOut(
                runId: $wait['run_id'],
                swarmClass: $wait['swarm_class'],
                topology: $wait['topology'],
                waitName: $waitName,
                executionMode: $this->runs->publicLifecycleExecutionMode($wait),
            ));

            if ($updated['status'] === 'pending') {
                $dispatch = $dispatchStep($wait['run_id'], (int) $updated['next_step_index'], $updated['queue_connection'], $updated['queue_name']);
                unset($dispatch);
                $this->durableRuns->markRecoveryDispatched($wait['run_id']);
            } elseif ($updated['status'] === 'waiting') {
                // Still at its branch boundary (or another wait is open): release the join
                // if it is ready, now that the closure has committed.
                $this->joinRelease->releaseAfterCommit($wait['run_id']);
            }
        }

        $childParents = $this->durableRuns->parentsWaitingOnTerminalChildren(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($childParents as $run) {
            $this->children->reconcileTerminalChildrenForParent($run);
        }

        $undispatchedChildren = $this->durableRuns->undispatchedChildRuns(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($undispatchedChildren as $child) {
            // The sweep decrypts non-strictly (so one bad row can't abort the cross-run
            // batch). Re-read this child strictly here: a wrong/rotated APP_KEY throws and
            // we SKIP it (it stays pending → re-swept next pass, re-dispatchable once the key
            // is corrected) rather than feeding a null/ciphertext payload into its resume or
            // marking it permanently failed.
            try {
                $strictChild = $this->durableRuns->childRunForChild((string) $child['child_run_id']);
            } catch (SwarmException $exception) {
                $this->logger->warning('laravel-swarm: skipping undecryptable durable child during recovery; it will retry after APP_KEY is corrected.', [
                    'child_run_id' => $child['child_run_id'] ?? null,
                    'parent_run_id' => $child['parent_run_id'] ?? null,
                ]);

                continue;
            }

            if ($strictChild !== null) {
                $this->children->dispatchChildIntent($strictChild);
            }
        }

        // Fan-out cap liveness net: release held branches into free slots of every
        // run holding some, e.g. after a worker died between a branch's commit and
        // its reconcile. Never releases past a run's cap.
        $admitted = $this->admission->reconcileHeldRuns($runId, $swarmClass);

        return array_values(array_unique(array_merge(
            $admitted,
            array_map(static fn (array $run): string => $run['run_id'], $runs),
            array_map(static fn (array $branch): string => $branch['run_id'], $branches),
            array_map(static fn (array $run): string => $run['run_id'], $dueRetryRuns),
            array_map(static fn (array $branch): string => $branch['run_id'], $dueRetryBranches),
            array_values(array_diff(array_map(static fn (array $run): string => $run['run_id'], $waitingJoins), $failedJoinRunIds)),
            array_map(static fn (array $run): string => $run['run_id'], $queuedResumes),
            array_map(static fn (array $run): string => $run['run_id'], $timedOutWaits),
            array_map(static fn (array $run): string => $run['run_id'], $childParents),
            array_map(static fn (array $child): string => $child['parent_run_id'], $undispatchedChildren),
        )));
    }
}
