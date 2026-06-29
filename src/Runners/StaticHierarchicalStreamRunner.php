<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Concerns\MergesAgentUsage;
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\GuardrailParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmStreamProviderException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Memory\AgentVisibleMemoryView;
use BuiltByBerry\LaravelSwarm\Memory\MemoryReplayCoordinator;
use BuiltByBerry\LaravelSwarm\Memory\SnapshotToolCallNormalizer;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalFinishNode;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalParallelNode;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalWorkerNode;
use BuiltByBerry\LaravelSwarm\Runners\Concerns\RecordsUnknownStreamEvents;
use BuiltByBerry\LaravelSwarm\Streaming\ContextGrowthGovernor;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalSealBarrier;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeChildrenDecided;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeClosed;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeOpened;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Streaming\Events\Error as ProviderStreamError;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Streaming execution for StaticHierarchical swarms.
 *
 * Sequential worker nodes stream live text deltas. Parallel groups use one of two modes,
 * controlled by #[StreamParallelBranches] or swarm.static_hierarchical.stream_parallel_branches:
 *   concurrent  — branches run via ConcurrencyManager (no text deltas from branches).
 *   sequential  — branches stream one at a time in declaration order.
 *
 * Resume scope — hierarchical re-executes, it does not skip. The idempotent
 * multi-step resume that skips already-completed steps (issue #202,
 * {@see SequentialRunner}'s checkpoint store) is Sequential-only. On a
 * crash-resume re-run, this runner re-executes every reachable worker — each
 * runs under its frozen-snapshot view (issue #192), so the agent-visible memory
 * is deterministic, but the worker's provider IS re-invoked and the observable
 * side effects repeat: usage is re-accounted, `step.completed` telemetry/audit
 * fire again, and stream events are re-emitted to the consumer. A consumer
 * streaming a hierarchical run therefore sees duplicate usage/telemetry across
 * the crashed and resumed attempts; a Sequential consumer does not. The
 * determinism guarantee (frozen-view replay) holds for both; only the skip
 * optimisation is Sequential-only.
 *
 * @phpstan-import-type SwarmTaskInput from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 *
 * @internal
 */
class StaticHierarchicalStreamRunner extends SequentialStreamRunner
{
    use MergesAgentUsage;
    use RecordsUnknownStreamEvents;

    public function __construct(
        ConfigRepository $config,
        ContextStore $contextStore,
        ArtifactRepository $artifactRepository,
        RunHistoryStore $historyStore,
        StreamEventStore $streamEvents,
        Dispatcher $events,
        SequentialRunner $sequential,
        SwarmCapture $capture,
        SwarmPayloadLimits $limits,
        SwarmAttributeResolver $resolver,
        SwarmAuditDispatcher $audit,
        SwarmTelemetryDispatcher $telemetry,
        SwarmGuardrailRunner $guardrails,
        LoggerInterface $logger,
        ContextGrowthGovernor $growthGovernor,
        protected HierarchicalRoutePlanner $planner,
        protected ConcurrencyManager $concurrency,
        protected SwarmStepRecorder $stepsRecorder,
        protected SnapshotsMemory $snapshots,
        protected AgentVisibleMemoryView $view,
        protected MemoryReplayCoordinator $coordinator,
    ) {
        parent::__construct(
            $config,
            $contextStore,
            $artifactRepository,
            $historyStore,
            $streamEvents,
            $events,
            $sequential,
            $capture,
            $limits,
            $resolver,
            $audit,
            $telemetry,
            $guardrails,
            $logger,
            $growthGovernor,
        );
    }

    /**
     * @param  SwarmTaskInput  $task
     */
    public function stream(Swarm $swarm, string|array|RunContext $task): StreamableSwarmResponse
    {
        if (! $swarm instanceof HasRoutePlan) {
            throw new SwarmException(
                $swarm::class.': static hierarchical swarms must implement HasRoutePlan and define a plan() method.'
            );
        }

        $agents = $swarm->agents();

        if ($agents === []) {
            throw new SwarmException('Static hierarchical swarms must define at least one agent.');
        }

        // Build worker map and validate uniqueness
        $workerMap = [];
        foreach ($agents as $agent) {
            $agentClass = $agent::class;
            if (isset($workerMap[$agentClass])) {
                throw new SwarmException(
                    $swarm::class.': agents() contains duplicate agent class '.$agentClass.'. Static hierarchical worker classes must be unique.'
                );
            }
            $workerMap[$agentClass] = $agent;
        }

        $topology = Topology::StaticHierarchical;
        $timeoutSeconds = $this->resolver->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolver->resolveMaxAgentExecutions($swarm);
        $parallelMode = $this->resolver->resolveStreamParallelBranches($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $context = RunContext::fromTask($task);
        $this->checkInputPayload($task, $context);
        $context->mergeMetadata([
            'swarm_class' => $swarm::class,
            'topology' => $topology->value,
        ]);

        $plan = $this->planner->fromStaticPlan($agents, $swarm->plan(), $swarm::class);

        // Enforce execution budget before any LLM call
        $required = $plan->reachableWorkerCount();
        if ($required > $maxAgentExecutions) {
            throw new SwarmException(sprintf(
                '%s: static route plan requires %d agent executions but the swarm allows %d. '
                ."Increase #[MaxAgentSteps] or reduce the plan's worker nodes.",
                $swarm::class,
                $required,
                $maxAgentExecutions,
            ));
        }

        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: $topology,
            executionMode: ExecutionMode::Stream,
            deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: $maxAgentExecutions,
            ttlSeconds: $contextTtl,
            leaseSeconds: null,
            executionToken: null,
            verifyOwnership: null,
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
            queueHierarchicalParallelCoordination: null,
        );

        // Validate input eagerly — consistent with prompt() and queue().
        try {
            $this->guardrails->validateInput($swarm, $context);
        } catch (Throwable $exception) {
            if ($exception instanceof GuardrailViolation) {
                $context->mergeMetadata($exception->safeContextMetadata());
            }
            $this->historyStore->recordPreflightFailure(
                $context->runId,
                $swarm::class,
                $topology->value,
                $context,
                $context->metadata,
                $exception,
                $contextTtl,
            );
            $this->events->dispatch(new SwarmFailed(
                runId: $context->runId,
                swarmClass: $swarm::class,
                topology: $topology->value,
                exception: $this->capture->failureException($exception),
                durationMs: 0,
                metadata: $context->metadata,
                executionMode: ExecutionMode::Stream->value,
                exceptionClass: $exception::class,
            ));
            $this->audit->emit('run.failed', [
                'run_id' => $context->runId,
                'parent_run_id' => $context->metadata['parent_run_id'] ?? null,
                'swarm_class' => $swarm::class,
                'topology' => $topology->value,
                'execution_mode' => ExecutionMode::Stream->value,
                'status' => 'failed',
                'exception_class' => $exception::class,
                'duration_ms' => 0,
                ...$this->audit->metadata($context->metadata),
            ]);
            throw $exception;
        }

        $startedAt = null;

        return new StreamableSwarmResponse(
            runId: $context->runId,
            generator: function () use ($state, $context, $contextTtl, $swarm, $plan, $workerMap, $parallelMode, &$startedAt): \Generator {
                return yield from $this->executeStaticPlan($state, $context, $contextTtl, $swarm, $plan, $workerMap, $parallelMode, $startedAt);
            },
            streamEvents: $this->streamEvents,
            ttlSeconds: $contextTtl,
            storesForReplay: (bool) $this->config->get('swarm.streaming.replay.enabled', false),
            replayFailurePolicy: (string) $this->config->get('swarm.streaming.replay.failure_policy', 'fail'),
            onReplayFailure: function (Throwable $exception) use ($state, $context, $contextTtl, $swarm, &$startedAt): SwarmStreamError {
                $replayStreamSeq = 0;
                $replayStreamStart = MonotonicTime::now();

                return $this->failStream($state, $context, $contextTtl, $swarm, $exception, $startedAt, $replayStreamStart, $replayStreamSeq);
            },
            onAbandoned: function (SwarmException $exception) use ($state, $context, $contextTtl, $swarm, &$startedAt): void {
                $abandonStreamSeq = 0;
                $abandonStreamStart = MonotonicTime::now();
                $this->failStream($state, $context, $contextTtl, $swarm, $exception, $startedAt, $abandonStreamStart, $abandonStreamSeq);
            },
        );
    }

    /**
     * @param  array<class-string, Agent>  $workerMap
     * @return \Generator<int, SwarmStreamEvent, null, SwarmResponse>
     */
    protected function executeStaticPlan(
        SwarmExecutionState $state,
        RunContext $context,
        int $contextTtl,
        Swarm $swarm,
        HierarchicalRoutePlan $plan,
        array $workerMap,
        string $parallelMode,
        ?float &$startedAt,
    ): \Generator {
        $historyRowStarted = false;

        $this->historyStore->start($context->runId, $swarm::class, $state->topology->value, $this->capture->context($context), $context->metadata, $contextTtl);
        $this->contextStore->put($this->capture->activeContext($context), $contextTtl);
        $this->events->dispatch(new SwarmStarted(
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $state->topology->value,
            input: $this->capture->applyInput($context->input, $context),
            metadata: $context->metadata,
            executionMode: ExecutionMode::Stream->value,
        ));
        $this->audit->emit('run.started', [
            'run_id' => $context->runId,
            'parent_run_id' => $context->metadata['parent_run_id'] ?? null,
            'swarm_class' => $swarm::class,
            'topology' => $state->topology->value,
            'execution_mode' => ExecutionMode::Stream->value,
            'status' => 'started',
            ...$this->audit->metadata($context->metadata),
        ]);

        $streamTelemetryStart = MonotonicTime::now();
        $streamSequenceIndex = 0;

        $streamStartEvent = new SwarmStreamStart(
            id: SwarmStreamEvent::newId(),
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $state->topology->value,
            input: $this->capture->applyInput($context->input, $context),
            metadata: $context->metadata,
            timestamp: SwarmStreamEvent::timestamp(),
        );

        yield $streamStartEvent;
        $this->recordStreamTelemetry($swarm, $state, $streamStartEvent, $streamSequenceIndex, $streamTelemetryStart, false);

        $startedAt = MonotonicTime::now();
        $historyRowStarted = true;

        try {
            ['mergedUsage' => $mergedUsage, 'executedNodeIds' => $executedNodeIds, 'executedAgentClasses' => $executedAgentClasses, 'parallelGroups' => $parallelGroups, 'nextIndex' => $nextIndex]
                = yield from $this->drivePlanNodes(
                    state: $state,
                    context: $context,
                    contextTtl: $contextTtl,
                    swarm: $swarm,
                    plan: $plan,
                    workerMap: $workerMap,
                    parallelMode: $parallelMode,
                    nextIndex: 0,
                    initialParentNodeId: null,
                    streamSequenceIndex: $streamSequenceIndex,
                    streamTelemetryStart: $streamTelemetryStart,
                );

            $context->mergeMetadata([
                'usage' => $mergedUsage,
                'executed_node_ids' => $executedNodeIds,
                'executed_agent_classes' => $executedAgentClasses,
                'parallel_groups' => $parallelGroups,
                'executed_steps' => $nextIndex,
            ]);

            $response = $this->normalizeCompletionResponse(new SwarmResponse(
                output: (string) ($context->data['last_output'] ?? $context->input),
                context: $context,
                artifacts: $context->artifacts,
                usage: $mergedUsage,
                metadata: [
                    'topology' => $state->topology->value,
                    'route_plan_start' => $plan->startAt,
                    'execution_mode' => $state->executionMode->value,
                ],
            ), $context, $state->topology->value);

            $this->guardrails->validateOutput($swarm, $context, $response->output);

            $capturedResponse = $this->limits->response($this->capture->response($response));
            $this->contextStore->put($this->capture->terminalContext($context), $contextTtl);
            $this->historyStore->complete($context->runId, $capturedResponse, $contextTtl);
            $this->events->dispatch(new SwarmCompleted(
                runId: $context->runId,
                swarmClass: $swarm::class,
                topology: $state->topology->value,
                output: $this->capture->applyOutput($capturedResponse->output, $context),
                durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
                metadata: $capturedResponse->metadata,
                artifacts: $capturedResponse->artifacts,
                executionMode: ExecutionMode::Stream->value,
            ));
            $this->audit->emit('run.completed', [
                'run_id' => $context->runId,
                'parent_run_id' => $context->metadata['parent_run_id'] ?? null,
                'swarm_class' => $swarm::class,
                'topology' => $state->topology->value,
                'execution_mode' => ExecutionMode::Stream->value,
                'status' => 'completed',
                'duration_ms' => MonotonicTime::elapsedMilliseconds($startedAt),
                ...$this->audit->metadata($capturedResponse->metadata),
            ]);

            $streamEndEvent = new SwarmStreamEnd(
                id: SwarmStreamEvent::newId(),
                runId: $context->runId,
                output: $this->capture->applyOutput($capturedResponse->output, $context),
                usage: $capturedResponse->usage,
                metadata: $capturedResponse->metadata,
                timestamp: SwarmStreamEvent::timestamp(),
            );
            $this->recordStreamTelemetry($swarm, $state, $streamEndEvent, $streamSequenceIndex, $streamTelemetryStart, false);

            yield $streamEndEvent;

            // Record the seal barrier to the event store — it is an internal
            // compaction marker. Best-effort: a store failure here must not surface
            // to the caller.
            try {
                $this->streamEvents->record($context->runId, new SwarmCausalSealBarrier(
                    id: SwarmStreamEvent::newId(),
                    runId: $context->runId,
                    timestamp: SwarmStreamEvent::timestamp(),
                ), $contextTtl);
            } catch (Throwable) {
                // Intentionally swallowed.
            }

            return $response;
        } catch (Throwable $exception) {
            yield $this->failStream($state, $context, $contextTtl, $swarm, $exception, $startedAt, $streamTelemetryStart, $streamSequenceIndex, $historyRowStarted);

            throw $exception;
        }
    }

    /**
     * Drive the plan-walk generator for a route plan.
     *
     * Extracted so both StaticHierarchicalStreamRunner (index=0, parentNodeId=null)
     * and HierarchicalStreamRunner (index=1, parentNodeId='__coordinator__') can
     * reuse the same loop.
     *
     * @param  array<class-string, Agent>  $workerMap
     * @return \Generator<int, SwarmStreamEvent, null, array{mergedUsage: array<string, int>, executedNodeIds: list<string>, executedAgentClasses: list<string>, parallelGroups: list<array<string, mixed>>, nextIndex: int}>
     */
    protected function drivePlanNodes(
        SwarmExecutionState $state,
        RunContext $context,
        int $contextTtl,
        Swarm $swarm,
        HierarchicalRoutePlan $plan,
        array $workerMap,
        string $parallelMode,
        int $nextIndex,
        ?string $initialParentNodeId,
        int &$streamSequenceIndex,
        float $streamTelemetryStart,
    ): \Generator {
        $nodeOutputs = [];
        $mergedUsage = [];
        $executedNodeIds = [];
        $executedAgentClasses = [];
        $parallelGroups = [];
        $loopIterations = [];
        // Per-run context-growth throttle memory (#288). Generator-local so two
        // runs in one Octane worker never share warn/nudge bookkeeping.
        $growthState = [];
        // The enclosing node id for the next node opened (#284): null at the
        // root, then the most-recent decider once it declares its child.
        $parentNodeId = $initialParentNodeId;
        $currentNodeId = $plan->startAt;

        while ($currentNodeId !== null) {
            if (hrtime(true) >= $state->deadlineMonotonic) {
                throw new SwarmTimeoutException('The swarm exceeded its configured timeout while streaming.');
            }

            $node = $plan->node($currentNodeId);

            if ($node instanceof HierarchicalWorkerNode) {
                $agent = $workerMap[$node->agentClass];
                $input = $this->composeStaticPrompt($node->prompt, $node->withOutputs, $nodeOutputs, $node->id);
                $agentName = class_basename($agent::class);

                $iteration = ($loopIterations[$node->id] ?? 0) + 1;
                $stepMetadata = array_merge($node->metadata, ['node_id' => $node->id]);

                if ($node->hasLoop()) {
                    $stepMetadata['loop_iteration'] = $iteration;
                }

                // Open this node on the causal log (#284) before any event
                // tagged with its id — causal completeness. The node is
                // self-identifying (node_id == id); only the first iteration
                // of a looping node opens, so the open brackets the node once.
                if ($iteration === 1) {
                    $nodeOpenedEvent = (new SwarmNodeOpened(
                        id: $node->id,
                        runId: $context->runId,
                        parentNodeId: $parentNodeId,
                        role: $this->nodeRole($node->metadata),
                        rationale: null,
                        timestamp: SwarmStreamEvent::timestamp(),
                    ))->withNodeId($node->id);
                    yield $nodeOpenedEvent;
                    $this->recordStreamTelemetry($swarm, $state, $nodeOpenedEvent, $streamSequenceIndex, $streamTelemetryStart, false);
                }

                $this->stepsRecorder->started($state, $nextIndex, $agent::class, $input);

                $stepStartEvent = (new SwarmStepStart(
                    id: SwarmStreamEvent::newId(),
                    runId: $context->runId,
                    stepIndex: $nextIndex,
                    agentClass: $agent::class,
                    agent: $agentName,
                    input: $this->capture->applyInput($input, $context),
                    timestamp: SwarmStreamEvent::timestamp(),
                ))->withNodeId($node->id);
                yield $stepStartEvent;
                $this->recordStreamTelemetry($swarm, $state, $stepStartEvent, $streamSequenceIndex, $streamTelemetryStart, false);

                $stepStartedAt = MonotonicTime::now();

                // The snapshot is frozen (or replayed) inside streamAgentEvents,
                // after the run frame is entered, so begin()'s frozen-view
                // override lands on the same frame the agent reads through.
                // The node id is threaded in so every deliberation event the
                // node streams (text/reasoning/tool deltas) carries its tag.
                ['output' => $output, 'usage' => $stepUsage] = yield from $this->streamAgentEvents(
                    $agent, $input, $nextIndex, $context, $swarm, $state, $streamSequenceIndex, $streamTelemetryStart, $node->id,
                );

                $durationMs = MonotonicTime::elapsedMilliseconds($stepStartedAt);
                $this->guardrails->validateStep(
                    $swarm,
                    GuardrailStepContext::fromState($state, $nextIndex, $agent::class, $input, $output, $stepMetadata),
                    $context,
                );

                $step = $this->stepsRecorder->completed(
                    state: $state,
                    index: $nextIndex,
                    agentClass: $agent::class,
                    input: $input,
                    output: $output,
                    usage: $stepUsage,
                    durationMs: $durationMs,
                    metadata: $stepMetadata,
                );

                $nodeOutputs[$node->id] = $output;
                $executedNodeIds[] = $node->id;
                $executedAgentClasses[] = $node->agentClass;
                $mergedUsage = $this->mergeUsage($mergedUsage, $stepUsage);
                $stepOutput = $this->capture->applyOutput((string) ($step->artifacts[0]->content ?? $output), $context);

                $stepEndEvent = (new SwarmStepEnd(
                    id: SwarmStreamEvent::newId(),
                    runId: $context->runId,
                    stepIndex: $nextIndex,
                    agentClass: $agent::class,
                    agent: $agentName,
                    output: $stepOutput,
                    durationMs: $durationMs,
                    metadata: ['usage' => $stepUsage],
                    timestamp: SwarmStreamEvent::timestamp(),
                ))->withNodeId($node->id);
                yield $stepEndEvent;
                $this->recordStreamTelemetry($swarm, $state, $stepEndEvent, $streamSequenceIndex, $streamTelemetryStart, false);

                // Step boundary: govern hot context growth (#288). $streamSequenceIndex
                // is the in-process hot working-set event count.
                $this->growthGovernor->evaluate($swarm, $context->runId, $streamSequenceIndex, $growthState);

                $nextIndex++;
                $loopIterations[$node->id] = $iteration;
                $currentNodeId = $node->successor($iteration);

                // On a loop back-edge, reset every inner loop's counter so it
                // runs its full count again on the next outer pass. Mirrors the
                // sync path in HierarchicalRunner::executePlan().
                if ($node->hasLoop() && $currentNodeId === $node->loopTo) {
                    $plan->clearInnerLoopCounters($loopIterations, (string) $node->loopTo, $node->id);
                }

                // The node has finished deliberating. When it routes forward to
                // a child node, that fall-through IS its decision: declare the
                // chosen child (#284) so a reader (#283) sees the structure as
                // payload. A loop back-edge is not a child decision; the
                // looping node re-deliberates rather than spawning a child.
                $forwardChild = $node->successor($iteration);
                if ($forwardChild !== null && $forwardChild !== $node->loopTo) {
                    $childrenDecidedEvent = (new SwarmNodeChildrenDecided(
                        id: SwarmStreamEvent::newId(),
                        runId: $context->runId,
                        childNodeIds: [$forwardChild],
                        rationale: null,
                        timestamp: SwarmStreamEvent::timestamp(),
                    ))->withNodeId($node->id);
                    yield $childrenDecidedEvent;
                    $this->recordStreamTelemetry($swarm, $state, $childrenDecidedEvent, $streamSequenceIndex, $streamTelemetryStart, false);

                    // The decided child becomes the parent of the nodes opened
                    // beneath it as the plan is walked forward.
                    $parentNodeId = $node->id;
                }

                // Close the node once it will not loop again (the next hop is
                // not its own loop back-edge), bracketing every event tagged
                // with its id.
                if ($currentNodeId !== $node->loopTo) {
                    $nodeClosedEvent = (new SwarmNodeClosed(
                        id: SwarmStreamEvent::newId(),
                        runId: $context->runId,
                        result: $stepOutput,
                        timestamp: SwarmStreamEvent::timestamp(),
                    ))->withNodeId($node->id);
                    yield $nodeClosedEvent;
                    $this->recordStreamTelemetry($swarm, $state, $nodeClosedEvent, $streamSequenceIndex, $streamTelemetryStart, false);
                }

                continue;
            }

            if ($node instanceof HierarchicalParallelNode) {
                $executedNodeIds[] = $node->id;
                $parallelGroups[] = ['node_id' => $node->id, 'branches' => $node->branches];

                // If this fan-out sits inside a bounded loop, stamp each branch
                // step with the enclosing loop's current iteration so a parallel
                // group re-run is attributable to its pass — parity with the sync
                // path (HierarchicalRunner::parallelBranchMetadata).
                $enclosingLoopId = $plan->enclosingLoopNodeId($node->id);
                $parallelLoopIteration = $enclosingLoopId !== null
                    ? ($loopIterations[$enclosingLoopId] ?? 0) + 1
                    : null;
                $branchLoopMeta = $parallelLoopIteration !== null
                    ? ['loop_iteration' => $parallelLoopIteration]
                    : [];

                if ($parallelMode === 'sequential') {
                    // Stream each branch in declaration order
                    foreach ($node->branches as $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $plan->node($branchNodeId);
                        $agent = $workerMap[$branch->agentClass];
                        $input = $this->composeStaticPrompt($branch->prompt, $branch->withOutputs, $nodeOutputs, $branch->id);
                        $agentName = class_basename($agent::class);

                        $this->stepsRecorder->started($state, $nextIndex, $agent::class, $input);

                        $branchStartEvent = new SwarmStepStart(
                            id: SwarmStreamEvent::newId(),
                            runId: $context->runId,
                            stepIndex: $nextIndex,
                            agentClass: $agent::class,
                            agent: $agentName,
                            input: $this->capture->applyInput($input, $context),
                            timestamp: SwarmStreamEvent::timestamp(),
                        );
                        yield $branchStartEvent;
                        $this->recordStreamTelemetry($swarm, $state, $branchStartEvent, $streamSequenceIndex, $streamTelemetryStart, false);

                        $stepStartedAt = MonotonicTime::now();

                        // The snapshot is frozen (or replayed) inside
                        // streamAgentEvents, after the run frame is entered.
                        ['output' => $output, 'usage' => $stepUsage] = yield from $this->streamAgentEvents(
                            $agent, $input, $nextIndex, $context, $swarm, $state, $streamSequenceIndex, $streamTelemetryStart,
                        );

                        $durationMs = MonotonicTime::elapsedMilliseconds($stepStartedAt);
                        $this->guardrails->validateStep(
                            $swarm,
                            GuardrailStepContext::fromState(
                                $state,
                                $nextIndex,
                                $agent::class,
                                $input,
                                $output,
                                array_merge($branch->metadata, ['node_id' => $branch->id, 'parent_parallel_node_id' => $node->id], $branchLoopMeta),
                            ),
                            $context,
                        );

                        $step = $this->stepsRecorder->completed(
                            state: $state,
                            index: $nextIndex,
                            agentClass: $agent::class,
                            input: $input,
                            output: $output,
                            usage: $stepUsage,
                            durationMs: $durationMs,
                            metadata: array_merge($branch->metadata, ['node_id' => $branch->id, 'parent_parallel_node_id' => $node->id], $branchLoopMeta),
                            updateContext: false,
                            storeContext: false,
                        );

                        $nodeOutputs[$branch->id] = $output;
                        $executedNodeIds[] = $branch->id;
                        $executedAgentClasses[] = $branch->agentClass;
                        $mergedUsage = $this->mergeUsage($mergedUsage, $stepUsage);
                        $stepOutput = $this->capture->applyOutput((string) ($step->artifacts[0]->content ?? $output), $context);

                        $branchEndEvent = new SwarmStepEnd(
                            id: SwarmStreamEvent::newId(),
                            runId: $context->runId,
                            stepIndex: $nextIndex,
                            agentClass: $agent::class,
                            agent: $agentName,
                            output: $stepOutput,
                            durationMs: $durationMs,
                            metadata: ['usage' => $stepUsage],
                            timestamp: SwarmStreamEvent::timestamp(),
                        );
                        yield $branchEndEvent;
                        $this->recordStreamTelemetry($swarm, $state, $branchEndEvent, $streamSequenceIndex, $streamTelemetryStart, false);

                        // Step boundary: a parallel branch end is a step end too, so a
                        // fan-out-dominated run is governed for context growth (#288).
                        $this->growthGovernor->evaluate($swarm, $context->runId, $streamSequenceIndex, $growthState);

                        $nextIndex++;
                    }
                } else {
                    // Concurrent mode: branches run via ConcurrencyManager (no live text deltas)
                    $branchDefinitions = [];
                    $branchSnapshots = [];
                    $callbacks = [];

                    foreach ($node->branches as $ordinal => $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $plan->node($branchNodeId);
                        $input = $this->composeStaticPrompt($branch->prompt, $branch->withOutputs, $nodeOutputs, $branch->id);

                        // Persist the branch snapshot BEFORE ConcurrencyManager
                        // dispatches, so each (possibly forked) child's begin()
                        // can find() it and reconstruct its own frozen view. On
                        // a crash-resume the prior frozen snapshot is preserved
                        // (its entries are the determinism record) with tool
                        // calls reset so this attempt rebuilds them; on a fresh
                        // attempt a new snapshot is frozen from the live view.
                        $existingBranchSnapshot = $this->coordinator->existingSnapshot(
                            $swarm::class,
                            $context->runId,
                            $nextIndex + $ordinal,
                        );

                        $branchSnapshots[$ordinal] = $existingBranchSnapshot !== null
                            ? $this->snapshots->resetToolCalls($existingBranchSnapshot)
                            : $this->snapshots->snapshot(
                                $context->runId,
                                $nextIndex + $ordinal,
                                $this->view->present($swarm, $context, $workerMap[$branch->agentClass]),
                            );

                        $this->stepsRecorder->started($state, $nextIndex + $ordinal, $branch->agentClass, $input);

                        $branchDefinitions[$branchNodeId] = [
                            'node' => $branch,
                            'input' => $input,
                            'index' => $nextIndex + $ordinal,
                            'ordinal' => $ordinal,
                        ];

                        $agentClass = $branch->agentClass;
                        $branchRunId = $state->context->runId;
                        $branchSwarmClass = $state->swarm::class;
                        $branchContextPayload = $state->context->toQueuePayload();
                        $branchStepIndex = $nextIndex + $ordinal;
                        // A `static` closure that resolves every collaborator
                        // from the container — it MUST NOT bind `$this`. The
                        // real ProcessDriver serializes this callback with
                        // SerializableClosure to ship it to a child process;
                        // binding `$this` would drag the entire runner object
                        // graph (every injected collaborator) into the
                        // serialization and blow up. The agent and the
                        // MemoryReplayCoordinator are both re-resolved from the
                        // child's container instead, mirroring how the worker
                        // agent is resolved below.
                        $callbacks[$ordinal] = static function () use ($agentClass, $input, $branchRunId, $branchSwarmClass, $branchContextPayload, $branchStepIndex): array {
                            $container = Container::getInstance();
                            $worker = $container->make($agentClass);

                            if (! $worker instanceof Agent) {
                                throw new SwarmException("Static hierarchical parallel worker [{$agentClass}] must resolve to a Laravel AI agent.");
                            }

                            ActiveRunContext::enter($branchRunId, $branchSwarmClass, RunContext::fromPayload($branchContextPayload, $branchRunId));

                            // Each concurrent branch resolves its OWN frozen-view
                            // override from the DB. A process-local handle cannot
                            // cross a fork/process boundary, so the branch calls
                            // begin() itself: its find() is a DB read that works
                            // in any process and reconstructs this branch's own
                            // ReplaySwarmMemory as the frame override. The parent
                            // persisted this branch's snapshot before dispatch
                            // (above), so find() sees it. We use begin() only for
                            // the read override — the parent owns the tool-call
                            // append from the returned payload — so the returned
                            // boundary's snapshot is intentionally unused here.
                            // This is correct-by-construction across fork/process
                            // branches because F1 removed the shared-container
                            // mutation that made begin() unsafe inside a child.
                            //
                            // Resolve the coordinator defensively: a child
                            // process whose container has not booted the package
                            // bindings (so SnapshotsMemory is unresolvable) cannot
                            // replay anyway — there is no snapshot store to read —
                            // so it simply runs the branch fresh against live
                            // memory rather than failing the whole run. Real
                            // workers (Octane/queue) boot the provider, so they
                            // take the begin()/end() override path.
                            $coordinator = null;
                            $boundary = null;

                            try {
                                $coordinator = $container->make(MemoryReplayCoordinator::class);
                                $boundary = $coordinator->begin($branchSwarmClass, $branchRunId, $branchStepIndex);
                            } catch (BindingResolutionException) {
                                $coordinator = null;
                                $boundary = null;
                            }

                            try {
                                $branchStartedAt = MonotonicTime::now();
                                $response = $worker->prompt($input);

                                return [
                                    'output' => (string) $response,
                                    'usage' => $response->usage->toArray(),
                                    'duration_ms' => MonotonicTime::elapsedMilliseconds($branchStartedAt),
                                    'tool_calls' => SnapshotToolCallNormalizer::fromResponse($response),
                                ];
                            } finally {
                                if ($coordinator !== null && $boundary !== null) {
                                    $coordinator->end($boundary);
                                }
                                ActiveRunContext::exit();
                            }
                        };
                    }

                    /** @var array<int, array{output: string, usage: array<string, int>, duration_ms: int, tool_calls: list<array{name: string, arguments: array<string, mixed>, result: mixed, id: string|null, result_id: string|null}>}> $results */
                    $results = $this->concurrency->driver()->run($callbacks);

                    $policy = GuardrailParallelFailurePolicy::tryFrom((string) $this->config->get(
                        'swarm.guardrails.parallel_failure_policy',
                        GuardrailParallelFailurePolicy::Existing->value,
                    )) ?? GuardrailParallelFailurePolicy::Existing;

                    if ($policy === GuardrailParallelFailurePolicy::BatchValidateBeforeRecord) {
                        foreach ($node->branches as $branchNodeId) {
                            /** @var HierarchicalWorkerNode $branch */
                            $branch = $branchDefinitions[$branchNodeId]['node'];
                            $input = $branchDefinitions[$branchNodeId]['input'];
                            $index = $branchDefinitions[$branchNodeId]['index'];
                            $ordinal = $branchDefinitions[$branchNodeId]['ordinal'];

                            if (! array_key_exists($ordinal, $results)) {
                                throw new SwarmException($swarm::class.": static hierarchical parallel execution did not return a result for branch node [{$branchNodeId}].");
                            }

                            $row = $results[$ordinal];
                            $this->guardrails->validateStep(
                                $swarm,
                                GuardrailStepContext::fromState(
                                    $state,
                                    $index,
                                    $branch->agentClass,
                                    $input,
                                    $row['output'],
                                    array_merge($branch->metadata, ['node_id' => $branch->id, 'parent_parallel_node_id' => $node->id], $branchLoopMeta),
                                ),
                                $context,
                            );
                        }
                    }

                    foreach ($node->branches as $ordinal => $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $branchDefinitions[$branchNodeId]['node'];
                        $input = $branchDefinitions[$branchNodeId]['input'];
                        $index = $branchDefinitions[$branchNodeId]['index'];

                        if (! array_key_exists($ordinal, $results)) {
                            throw new SwarmException($swarm::class.": static hierarchical parallel execution did not return a result for branch node [{$branchNodeId}].");
                        }

                        $row = $results[$ordinal];
                        $mergedMeta = array_merge($branch->metadata, [
                            'node_id' => $branch->id,
                            'parent_parallel_node_id' => $node->id,
                        ], $branchLoopMeta);

                        if ($policy === GuardrailParallelFailurePolicy::Existing) {
                            $this->guardrails->validateStep(
                                $swarm,
                                GuardrailStepContext::fromState($state, $index, $branch->agentClass, $input, $row['output'], $mergedMeta),
                                $context,
                            );
                        }

                        $step = $this->stepsRecorder->completed(
                            state: $state,
                            index: $index,
                            agentClass: $branch->agentClass,
                            input: $input,
                            output: $row['output'],
                            usage: $row['usage'],
                            durationMs: $row['duration_ms'],
                            metadata: $mergedMeta,
                            updateContext: false,
                            storeContext: false,
                            includeUsageInMetadata: false,
                        );

                        $mergedUsage = $this->mergeUsage($mergedUsage, $row['usage']);
                        $nodeOutputs[$branch->id] = $step->output;
                        $executedNodeIds[] = $branch->id;
                        $executedAgentClasses[] = $branch->agentClass;

                        $branchEndEvent = new SwarmStepEnd(
                            id: SwarmStreamEvent::newId(),
                            runId: $context->runId,
                            stepIndex: $index,
                            agentClass: $branch->agentClass,
                            agent: class_basename($branch->agentClass),
                            output: $this->capture->applyOutput((string) ($step->artifacts[0]->content ?? $row['output']), $context),
                            durationMs: $row['duration_ms'],
                            metadata: ['usage' => $row['usage']],
                            timestamp: SwarmStreamEvent::timestamp(),
                        );
                        yield $branchEndEvent;
                        $this->recordStreamTelemetry($swarm, $state, $branchEndEvent, $streamSequenceIndex, $streamTelemetryStart, false);

                        // Step boundary: govern context growth on each joined branch
                        // result too (#288). The concurrent work is already complete
                        // here, so a refusal aborts cleanly without racing a branch.
                        $this->growthGovernor->evaluate($swarm, $context->runId, $streamSequenceIndex, $growthState);
                    }

                    $this->contextStore->put($this->capture->activeContext($context), $contextTtl);

                    foreach ($node->branches as $ordinal => $branchNodeId) {
                        if (! array_key_exists($ordinal, $results)) {
                            continue;
                        }
                        foreach ($results[$ordinal]['tool_calls'] as $toolCall) {
                            $branchSnapshots[$ordinal] = $this->snapshots->appendToolCall($branchSnapshots[$ordinal], $toolCall);
                        }
                    }

                    $nextIndex += count($node->branches);
                }

                $currentNodeId = $node->next;

                continue;
            }

            /** @var HierarchicalFinishNode $node */
            $finalOutput = $node->outputFrom !== null
                ? ($nodeOutputs[$node->outputFrom] ?? '')
                : ($node->output ?? '');

            $executedNodeIds[] = $node->id;

            // Override last_output with finish node's result (may differ from last worker)
            $context->mergeData(['last_output' => $finalOutput]);
            $currentNodeId = null;
        }

        return [
            'mergedUsage' => $mergedUsage,
            'executedNodeIds' => $executedNodeIds,
            'executedAgentClasses' => $executedAgentClasses,
            'parallelGroups' => $parallelGroups,
            'nextIndex' => $nextIndex,
        ];
    }

    /**
     * Stream events from one agent and yield them as SwarmStreamEvents.
     *
     * Returns the accumulated text output and step usage so the caller can record the step,
     * run guardrails, and emit SwarmStepEnd without duplicating the inner event loop.
     *
     * @return \Generator<int, SwarmStreamEvent, null, array{output: string, usage: array<string, int>}>
     */
    protected function streamAgentEvents(
        Agent $agent,
        string $input,
        int $stepIndex,
        RunContext $context,
        Swarm $swarm,
        SwarmExecutionState $state,
        int &$streamSequenceIndex,
        float $streamTelemetryStart,
        ?string $nodeId = null,
    ): \Generator {
        $output = '';
        $stepUsage = [];
        /** @var array<string, ToolCallData> $pendingToolCalls */
        $pendingToolCalls = [];
        /** @var array<string, true> $unknownStreamEventClasses */
        $unknownStreamEventClasses = [];

        // Publish the active run for the agent's RemembersRunContext trait while
        // its stream is consumed; cleared in finally so it never leaks past the
        // streamed step.
        ActiveRunContext::enter($context->runId, $swarm::class, $context);

        // Open the snapshot-backed replay boundary now that the run frame exists,
        // mirroring SequentialRunner. begin() detects a prior frozen snapshot,
        // installs the frozen-view override on the frame just entered, and returns
        // it for resetToolCalls() below; on the fresh path it freezes a new
        // snapshot from the (live or frozen) view. The override and any pending
        // tool calls are torn down in the finally so neither leaks past this step.
        $boundary = $this->coordinator->begin($swarm::class, $context->runId, $stepIndex);

        $snapshot = $boundary->isReplay()
            ? $this->snapshots->resetToolCalls($boundary->snapshot)
            : $this->snapshots->snapshot(
                $context->runId,
                $stepIndex,
                $this->view->present($swarm, $context, $agent),
            );

        try {
            foreach ($agent->stream($input) as $event) {
                if ($event instanceof TextDelta) {
                    $output .= $event->delta;
                    $swarmEvent = new SwarmTextDelta(
                        id: $event->id,
                        runId: $context->runId,
                        stepIndex: $stepIndex,
                        agentClass: $agent::class,
                        delta: $this->capture->applyOutput($event->delta, $context),
                        timestamp: $event->timestamp,
                    );
                    $this->syncInvocationId($swarmEvent, $event->invocationId);
                    $this->tagNode($swarmEvent, $nodeId);
                    yield $swarmEvent;
                    $this->recordStreamTelemetry($swarm, $state, $swarmEvent, $streamSequenceIndex, $streamTelemetryStart, false);
                } elseif ($event instanceof TextEnd) {
                    $swarmEvent = new SwarmTextEnd(
                        id: $event->id,
                        runId: $context->runId,
                        stepIndex: $stepIndex,
                        agentClass: $agent::class,
                        messageId: $event->messageId,
                        timestamp: $event->timestamp,
                    );
                    $this->syncInvocationId($swarmEvent, $event->invocationId);
                    $this->tagNode($swarmEvent, $nodeId);
                    yield $swarmEvent;
                    $this->recordStreamTelemetry($swarm, $state, $swarmEvent, $streamSequenceIndex, $streamTelemetryStart, false);
                } elseif ($event instanceof ReasoningDelta) {
                    $swarmEvent = new SwarmReasoningDelta(
                        id: $event->id,
                        runId: $context->runId,
                        stepIndex: $stepIndex,
                        agentClass: $agent::class,
                        reasoningId: $event->reasoningId,
                        delta: $this->capture->applyOutput($event->delta, $context),
                        timestamp: $event->timestamp,
                        summary: $this->captureStaticReasoningSummary($event->summary, $context),
                    );
                    $this->syncInvocationId($swarmEvent, $event->invocationId);
                    $this->tagNode($swarmEvent, $nodeId);
                    yield $swarmEvent;
                    $this->recordStreamTelemetry($swarm, $state, $swarmEvent, $streamSequenceIndex, $streamTelemetryStart, false);
                } elseif ($event instanceof ReasoningEnd) {
                    $swarmEvent = new SwarmReasoningEnd(
                        id: $event->id,
                        runId: $context->runId,
                        stepIndex: $stepIndex,
                        agentClass: $agent::class,
                        reasoningId: $event->reasoningId,
                        timestamp: $event->timestamp,
                        summary: $this->captureStaticReasoningSummary($event->summary, $context),
                    );
                    $this->syncInvocationId($swarmEvent, $event->invocationId);
                    $this->tagNode($swarmEvent, $nodeId);
                    yield $swarmEvent;
                    $this->recordStreamTelemetry($swarm, $state, $swarmEvent, $streamSequenceIndex, $streamTelemetryStart, false);
                } elseif ($event instanceof ToolCall) {
                    $pendingToolCalls[$event->toolCall->id] = $event->toolCall;

                    $swarmEvent = new SwarmToolCall(
                        id: $event->id,
                        runId: $context->runId,
                        stepIndex: $stepIndex,
                        agentClass: $agent::class,
                        toolCall: $this->captureStaticToolCall($event->toolCall, $context),
                        timestamp: $event->timestamp,
                    );
                    $this->syncInvocationId($swarmEvent, $event->invocationId);
                    $this->tagNode($swarmEvent, $nodeId);
                    yield $swarmEvent;
                    $this->recordStreamTelemetry($swarm, $state, $swarmEvent, $streamSequenceIndex, $streamTelemetryStart, false);
                } elseif ($event instanceof ToolResult) {
                    $matchedCallId = $event->toolResult->id;
                    $matchedCall = $pendingToolCalls[$matchedCallId] ?? null;

                    if ($matchedCall !== null) {
                        unset($pendingToolCalls[$matchedCallId]);
                        $snapshot = $this->snapshots->appendToolCall(
                            $snapshot,
                            SnapshotToolCallNormalizer::entry($matchedCall, $event->toolResult),
                        );
                    }

                    $swarmEvent = new SwarmToolResult(
                        id: $event->id,
                        runId: $context->runId,
                        stepIndex: $stepIndex,
                        agentClass: $agent::class,
                        toolResult: $this->captureStaticToolResult($event->toolResult, $context),
                        successful: $event->successful,
                        error: $this->captureStaticToolError($event->error, $context),
                        timestamp: $event->timestamp,
                    );
                    $this->syncInvocationId($swarmEvent, $event->invocationId);
                    $this->tagNode($swarmEvent, $nodeId);
                    yield $swarmEvent;
                    $this->recordStreamTelemetry($swarm, $state, $swarmEvent, $streamSequenceIndex, $streamTelemetryStart, false);
                } elseif ($event instanceof StreamEnd) {
                    $stepUsage = $event->usage->toArray();
                } elseif ($event instanceof ProviderStreamError) {
                    throw new SwarmStreamProviderException(
                        message: $event->message,
                        eventId: $event->id,
                        invocationId: $event->invocationId,
                        recoverable: $event->recoverable,
                        metadata: $this->captureStaticProviderErrorMetadata($event),
                        timestamp: $event->timestamp,
                        providerErrorType: $event->type,
                    );
                } else {
                    // An event type this chain does not map. Record its type
                    // (never its payload) so the silent snapshot drop becomes a
                    // visible breadcrumb; never throw — a harmless new provider
                    // event must not abort an otherwise-successful run.
                    // get_debug_type, not ::class: the branch exists to catch
                    // contract violations, so it must not itself fatal on a
                    // non-object event.
                    $unknownStreamEventClasses[get_debug_type($event)] = true;
                }
            }

            return ['output' => $output, 'usage' => $stepUsage];
        } finally {
            // Flush any tool calls without a matching ToolResult into the
            // snapshot — on the happy path (calls pending at stream end) AND on
            // abandonment (the generator was torn down mid-stream, so the foreach
            // never reached StreamEnd). Doing it in finally makes the append
            // crash-safe: an in-flight call is persisted with result=null instead
            // of being lost, mirroring SequentialRunner.
            foreach ($pendingToolCalls as $unpairedCall) {
                $snapshot = $this->snapshots->appendToolCall(
                    $snapshot,
                    SnapshotToolCallNormalizer::entry($unpairedCall),
                );
            }

            // Clear the frozen-view override even if the stream was abandoned
            // mid-flight (no-op on the fresh-execution path), then exit the run
            // frame. Flush first, then exit, so the frame is still live while the
            // snapshot append runs.
            $this->coordinator->end($boundary);
            ActiveRunContext::exit();

            // One breadcrumb per step for any stream events this chain did not
            // recognize (also fires if the generator was abandoned mid-stream).
            // Logs the dropped event classes; never throws.
            $this->breadcrumbUnknownStreamEvents(
                $unknownStreamEventClasses,
                $context->runId,
                $stepIndex,
            );
        }
    }

    /**
     * Compose a prompt from a static worker node, injecting named outputs from prior nodes.
     *
     * @param  array<string, string>  $withOutputs
     * @param  array<string, string>  $nodeOutputs
     */
    protected function composeStaticPrompt(string $prompt, array $withOutputs, array $nodeOutputs, string $nodeId): string
    {
        if ($withOutputs === []) {
            return $prompt;
        }

        $sections = [];

        foreach ($withOutputs as $alias => $sourceNodeId) {
            if (! array_key_exists($sourceNodeId, $nodeOutputs)) {
                throw new SwarmException("Static hierarchical worker node [{$nodeId}] cannot resolve named output [{$alias}] from unexecuted node [{$sourceNodeId}].");
            }

            $sections[] = "[{$alias}]\n".$nodeOutputs[$sourceNodeId];
        }

        return rtrim($prompt)."\n\nNamed outputs:\n".implode("\n\n", $sections);
    }

    // -------------------------------------------------------------------------
    // Streaming event helpers (mirrors SequentialRunner — cannot call protected
    // methods on an injected instance, so we implement them here)
    // -------------------------------------------------------------------------

    protected function syncInvocationId(SwarmStreamEvent $swarmEvent, ?string $invocationId): void
    {
        if (is_string($invocationId)) {
            $swarmEvent->withInvocationId($invocationId);
        }
    }

    /**
     * Tag a streamed deliberation event with the structural node it belongs to
     * (#284). A null node id leaves the event top-level — the same fail-safe as
     * the invocation-id carry above.
     */
    protected function tagNode(SwarmStreamEvent $swarmEvent, ?string $nodeId): void
    {
        if (is_string($nodeId)) {
            $swarmEvent->withNodeId($nodeId);
        }
    }

    /**
     * The structural role a node opens under (#284). A node's plan metadata may
     * name its role (e.g. 'coordinator'); absent that it is a plain 'worker'.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function nodeRole(array $metadata): string
    {
        return is_string($metadata['node_role'] ?? null) ? $metadata['node_role'] : 'worker';
    }

    protected function captureStaticToolCall(ToolCallData $toolCall, ?RunContext $context = null): ToolCallData
    {
        $decision = $this->capture->outputsDecision($context);

        if ($decision === CaptureDecision::Full) {
            return $toolCall;
        }

        if ($decision === CaptureDecision::Skip) {
            return new ToolCallData(
                id: $toolCall->id,
                name: $toolCall->name,
                arguments: [],
                resultId: $toolCall->resultId,
                reasoningId: $toolCall->reasoningId,
                reasoningSummary: null,
            );
        }

        return new ToolCallData(
            id: $toolCall->id,
            name: $toolCall->name,
            arguments: $this->redactStaticArrayPreservingKeys($toolCall->arguments),
            resultId: $toolCall->resultId,
            reasoningId: $toolCall->reasoningId,
            reasoningSummary: $toolCall->reasoningSummary === null
                ? null
                : $this->redactStaticArrayPreservingKeys($toolCall->reasoningSummary),
        );
    }

    protected function captureStaticToolResult(ToolResultData $toolResult, ?RunContext $context = null): ToolResultData
    {
        $decision = $this->capture->outputsDecision($context);

        if ($decision === CaptureDecision::Full) {
            return $toolResult;
        }

        if ($decision === CaptureDecision::Skip) {
            return new ToolResultData(
                id: $toolResult->id,
                name: $toolResult->name,
                arguments: [],
                result: null,
                resultId: $toolResult->resultId,
            );
        }

        return new ToolResultData(
            id: $toolResult->id,
            name: $toolResult->name,
            arguments: $this->redactStaticArrayPreservingKeys($toolResult->arguments),
            result: $this->redactStaticValue($toolResult->result),
            resultId: $toolResult->resultId,
        );
    }

    protected function captureStaticToolError(?string $error, ?RunContext $context = null): ?string
    {
        $decision = $this->capture->outputsDecision($context);

        if ($error === null || $decision === CaptureDecision::Full) {
            return $error;
        }

        if ($decision === CaptureDecision::Skip) {
            return null;
        }

        return '[redacted]';
    }

    /**
     * @param  array<int|string, mixed>|null  $summary
     * @return array<int|string, mixed>|null
     */
    protected function captureStaticReasoningSummary(?array $summary, ?RunContext $context = null): ?array
    {
        $decision = $this->capture->outputsDecision($context);

        if ($summary === null || $decision === CaptureDecision::Full || $decision === CaptureDecision::Skip) {
            return $decision === CaptureDecision::Skip ? null : $summary;
        }

        /** @var array<int, string> $redacted */
        $redacted = $this->redactStaticArrayPreservingKeys($summary);

        return $redacted;
    }

    /**
     * @return array<string, mixed>
     */
    protected function captureStaticProviderErrorMetadata(ProviderStreamError $event): array
    {
        $metadata = [
            'provider_error_type' => $event->type,
        ];

        if (is_array($event->metadata)) {
            $metadata['provider_metadata'] = $event->metadata;
        }

        if ($this->capture->capturesFailures()) {
            return $metadata;
        }

        return $this->redactStaticArrayPreservingKeys($metadata);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    protected function redactStaticArrayPreservingKeys(array $value): array
    {
        $redacted = [];

        foreach ($value as $key => $item) {
            $redacted[$key] = $this->redactStaticValue($item);
        }

        return $redacted;
    }

    protected function redactStaticValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->redactStaticArrayPreservingKeys($value);
        }

        return '[redacted]';
    }
}
