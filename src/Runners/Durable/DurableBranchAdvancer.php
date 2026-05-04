<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\SwarmStepRecorder;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Laravel\Ai\Contracts\Agent;
use Throwable;

class DurableBranchAdvancer
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected Dispatcher $events,
        protected SwarmStepRecorder $stepsRecorder,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected Application $application,
        protected DurableRunContext $runs,
        protected DurableBranchCoordinator $branches,
        protected DurableHierarchicalCoordinator $hierarchical,
        protected DurableRetryHandler $retryHandler,
    ) {}

    /**
     * @param  callable(string, string, ?string, ?string): \Illuminate\Foundation\Bus\PendingDispatch  $dispatchBranch
     * @param  callable(string, int, ?string, ?string): \Illuminate\Foundation\Bus\PendingDispatch     $dispatchStep
     * @param  callable(array<string, mixed>): void                                                    $dispatchQueuedResume
     * @param  callable(array<string, mixed>, string, \BuiltByBerry\LaravelSwarm\Support\RunContext, int, ?string, callable): void $failCurrentRun
     */
    public function advanceBranch(
        string $runId,
        string $branchId,
        callable $dispatchBranch,
        callable $dispatchStep,
        callable $dispatchQueuedResume,
        callable $failCurrentRun,
    ): void {
        $run = $this->runs->requireRun($runId);
        $branch = $this->durableRuns->findBranch($runId, $branchId);

        if ($branch === null || in_array($branch['status'], ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        if (($run['cancel_requested_at'] ?? null) !== null || $run['status'] === 'cancelled') {
            $this->durableRuns->cancelBranches($runId, $branch['parent_node_id']);

            return;
        }

        if (($run['pause_requested_at'] ?? null) !== null || $run['status'] === 'paused') {
            return;
        }

        $stepLeaseSeconds = $this->runs->validateStepTimeoutSeconds((int) $run['step_timeout_seconds']);
        $token = $this->durableRuns->acquireBranchLease($runId, $branchId, $stepLeaseSeconds);

        if ($token === null) {
            return;
        }

        $context = $this->runs->loadContext($runId);
        $swarm = $this->application->make($run['swarm_class']);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve durable swarm [{$run['swarm_class']}] from the container.");
        }

        $agent = $this->application->make($branch['agent_class']);

        if (! $agent instanceof Agent) {
            throw new SwarmException("Durable branch agent [{$branch['agent_class']}] must resolve to a Laravel AI agent.");
        }

        $this->durableRuns->markBranchRunning($runId, $branchId, $token);

        $timeoutSeconds = max((int) ceil((Carbon::parse($run['timeout_at'], 'UTC')->diffInSeconds(now('UTC'), false)) * -1), 1);
        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: Topology::from($run['topology']),
            executionMode: ExecutionMode::Durable,
            deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: (int) $run['total_steps'],
            ttlSeconds: $this->runs->ttlSeconds(),
            leaseSeconds: null,
            executionToken: null,
            verifyOwnership: fn (): null => $this->durableRuns->assertBranchOwned($runId, $branchId, $token),
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
            queueHierarchicalParallelCoordination: null,
        );

        $startedAt = MonotonicTime::now();
        $step = null;

        try {
            $this->stepsRecorder->started($state, (int) $branch['step_index'], $branch['agent_class'], $branch['input']);
            $response = $agent->prompt($branch['input']);
            $output = (string) $response;
            $usage = $response->usage->toArray();
            $durationMs = MonotonicTime::elapsedMilliseconds($startedAt);
            $step = $this->stepsRecorder->completed(
                state: $state,
                index: (int) $branch['step_index'],
                agentClass: $branch['agent_class'],
                input: $branch['input'],
                output: $output,
                usage: $usage,
                durationMs: $durationMs,
                metadata: is_array($branch['metadata'] ?? null) ? $branch['metadata'] : [],
                updateContext: false,
                storeContext: false,
                storeArtifacts: false,
            );

            $this->connection->transaction(function () use ($runId, $branch, $branchId, $token, $output, $usage, $durationMs, $step): void {
                if (is_string($branch['node_id'] ?? null)) {
                    $this->durableRuns->storeHierarchicalNodeOutput($runId, $branch['node_id'], $output, $this->runs->ttlSeconds());
                }

                $this->persistBranchStepArtifacts($runId, $step);
                $this->durableRuns->markBranchCompleted($runId, $branchId, $token, $output, $usage, $durationMs);
            });
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            return;
        } catch (Throwable $exception) {
            $retry = $this->retryHandler->scheduleBranchRetryIfAllowed($run, $branch, $swarm, $context, $token, $exception);
            if ($retry['scheduled']) {
                if ($retry['dispatchBranch'] !== null) {
                    $dispatchBranch($retry['dispatchBranch']['runId'], $retry['dispatchBranch']['branchId'], $retry['dispatchBranch']['connection'], $retry['dispatchBranch']['queue']);
                }

                return;
            }

            try {
                $this->durableRuns->markBranchFailed($runId, $branchId, $token, [
                    'message' => $this->capture->failureMessage($exception),
                    'class' => $exception::class,
                ]);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }

            if ($this->branches->parallelFailurePolicy($context) === DurableParallelFailurePolicy::FailRun) {
                $this->hierarchical->failWaitingParentFromBranches(
                    $run,
                    $context,
                    $stepLeaseSeconds,
                    function (array $run, string $token, $context, int $stepLeaseSeconds, ?string $parentNodeId) use ($failCurrentRun, $dispatchStep): void {
                        $failCurrentRun($run, $token, $context, $stepLeaseSeconds, $parentNodeId, $dispatchStep);
                    },
                );
            }
        }

        $run = $this->runs->requireRun($runId);
        $this->hierarchical->dispatchWaitingBoundary(
            $run,
            false,
            $dispatchStep,
            $dispatchBranch,
            $dispatchQueuedResume,
        );
    }

    protected function persistBranchStepArtifacts(string $runId, ?SwarmStep $step): void
    {
        if ($step === null || ! $this->capture->capturesArtifacts()) {
            return;
        }

        $this->artifactRepository->storeMany($runId, $step->artifacts, $this->runs->ttlSeconds());
    }
}
