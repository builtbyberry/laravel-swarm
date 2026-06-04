<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\AgentVisibleMemoryView;
use BuiltByBerry\LaravelSwarm\Memory\MemoryReplayCoordinator;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Memory\SnapshotToolCallNormalizer;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\SwarmGuardrailRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmStepRecorder;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal
 */
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
        protected SwarmGuardrailRunner $guardrails,
        protected DurableOutbox $outbox,
        protected DurableRunTerminalHandler $terminal,
        protected LoggerInterface $logger,
        protected SnapshotsMemory $snapshots,
        protected MemoryReplayCoordinator $coordinator,
        protected AgentVisibleMemoryView $view,
    ) {}

    public function advanceBranch(string $runId, string $branchId): void
    {
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

        // The coordinator handles snapshot detection, SwarmMemory binding swap,
        // and restore in a single boundary — the callback runs under the frozen
        // memory view on a crash-resume retry, or under live memory on a first
        // attempt. The callback returns `true` when boundary dispatch should
        // follow and `false` on all early-return paths.
        /** @var bool $shouldDispatch */
        $shouldDispatch = $this->coordinator->during(
            $run['swarm_class'],
            $runId,
            (int) $branch['step_index'],
            function (?MemorySnapshot $existing) use ($run, $branch, $runId, $branchId, $token, $context, $swarm, $stepLeaseSeconds): bool {
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

                    // On the fresh-execution path we freeze a new snapshot from live
                    // memory. On the replay path the snapshot already exists; we keep
                    // its frozen entries (the determinism guarantee) but clear the
                    // partial tool-call record so this attempt can rebuild it. Both
                    // paths converge on an unfrozen MemorySnapshot we can append to.
                    /** @var MemorySnapshot $snapshot */
                    $snapshot = $existing !== null
                        ? $this->snapshots->resetToolCalls($existing)
                        : $this->snapshots->snapshot(
                            $runId,
                            (int) $branch['step_index'],
                            $this->view->present($swarm, $context, $agent),
                        );

                    ActiveRunContext::enter($runId, $swarm::class, $context);

                    try {
                        $response = $agent->prompt($branch['input']);
                    } finally {
                        ActiveRunContext::exit();
                    }
                    $output = (string) $response;
                    $usage = $response->usage->toArray();
                    $durationMs = MonotonicTime::elapsedMilliseconds($startedAt);

                    foreach (SnapshotToolCallNormalizer::fromResponse($response) as $toolCall) {
                        $snapshot = $this->snapshots->appendToolCall($snapshot, $toolCall);
                    }
                    $this->guardrails->validateStep(
                        $swarm,
                        GuardrailStepContext::fromState(
                            $state,
                            (int) $branch['step_index'],
                            $branch['agent_class'],
                            $branch['input'],
                            $output,
                            is_array($branch['metadata'] ?? null) ? $branch['metadata'] : [],
                        ),
                        $state->context,
                    );
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
                    return false;
                } catch (Throwable $exception) {
                    $retry = $this->retryHandler->scheduleBranchRetryIfAllowed($run, $branch, $swarm, $context, $token, $exception);
                    if ($retry['scheduled']) {
                        return false;
                    }

                    // Branch is terminally failing — log before marking failed so the
                    // exception is visible in application logs even though this code
                    // path never rethrows (silent failure was the v0.3 / v0.4.0
                    // behavior; see issue #1).
                    $this->logger->error('Durable swarm branch failed — retries exhausted or non-retryable.', [
                        'run_id' => $runId,
                        'branch_id' => $branchId,
                        'agent_class' => (string) $branch['agent_class'],
                        'retry_attempt' => (int) ($branch['retry_attempt'] ?? 0),
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);

                    try {
                        $this->durableRuns->markBranchFailed($runId, $branchId, $token, [
                            'message' => $this->capture->failureMessage($exception),
                            'class' => $exception::class,
                        ]);
                    } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                        return false;
                    }

                    if ($this->branches->parallelFailurePolicy($context) === DurableParallelFailurePolicy::FailRun) {
                        $this->hierarchical->failWaitingParentFromBranches(
                            $run,
                            $context,
                            $stepLeaseSeconds,
                            function (array $freshRun, string $freshToken, RunContext $context, int $stepLeaseSeconds, ?string $parentNodeId): void {
                                $this->terminal->failCurrentRunFromBranchFailures($freshRun, $freshToken, $context, $stepLeaseSeconds, $parentNodeId);
                            },
                        );
                    }
                }

                return true;
            },
        );

        if ($shouldDispatch) {
            $run = $this->runs->requireRun($runId);
            $this->hierarchical->dispatchWaitingBoundary($run, false);
        }
    }

    protected function persistBranchStepArtifacts(string $runId, ?SwarmStep $step): void
    {
        if ($step === null || ! $this->capture->capturesArtifacts()) {
            return;
        }

        $this->artifactRepository->storeMany($runId, $step->artifacts, $this->runs->ttlSeconds());
    }
}
