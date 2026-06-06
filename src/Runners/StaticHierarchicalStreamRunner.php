<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

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
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalFinishNode;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalParallelNode;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalWorkerNode;
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
use BuiltByBerry\LaravelSwarm\Memory\AgentVisibleMemoryView;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Memory\SnapshotToolCallNormalizer;
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
use Throwable;

/**
 * Streaming execution for StaticHierarchical swarms.
 *
 * Sequential worker nodes stream live text deltas. Parallel groups use one of two modes,
 * controlled by #[StreamParallelBranches] or swarm.static_hierarchical.stream_parallel_branches:
 *   concurrent  — branches run via ConcurrencyManager (no text deltas from branches).
 *   sequential  — branches stream one at a time in declaration order.
 *
 * @phpstan-import-type SwarmTaskInput from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 *
 * @internal
 */
class StaticHierarchicalStreamRunner extends SequentialStreamRunner
{
    use MergesAgentUsage;

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
        protected HierarchicalRoutePlanner $planner,
        protected ConcurrencyManager $concurrency,
        protected SwarmStepRecorder $stepsRecorder,
        protected SnapshotsMemory $snapshots,
        protected AgentVisibleMemoryView $view,
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
            input: $this->capture->input($context->input),
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
            input: $this->capture->input($context->input),
            metadata: $context->metadata,
            timestamp: SwarmStreamEvent::timestamp(),
        );

        yield $streamStartEvent;
        $this->recordStreamTelemetry($swarm, $state, $streamStartEvent, $streamSequenceIndex, $streamTelemetryStart, false);

        $startedAt = MonotonicTime::now();
        $historyRowStarted = true;

        try {
            $nodeOutputs = [];
            $mergedUsage = [];
            $nextIndex = 0;
            $executedNodeIds = [];
            $executedAgentClasses = [];
            $parallelGroups = [];
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

                    $this->stepsRecorder->started($state, $nextIndex, $agent::class, $input);

                    $stepStartEvent = new SwarmStepStart(
                        id: SwarmStreamEvent::newId(),
                        runId: $context->runId,
                        stepIndex: $nextIndex,
                        agentClass: $agent::class,
                        agent: $agentName,
                        input: $this->capture->input($input),
                        timestamp: SwarmStreamEvent::timestamp(),
                    );
                    yield $stepStartEvent;
                    $this->recordStreamTelemetry($swarm, $state, $stepStartEvent, $streamSequenceIndex, $streamTelemetryStart, false);

                    $stepStartedAt = MonotonicTime::now();

                    $snapshot = $this->snapshots->snapshot(
                        $context->runId,
                        $nextIndex,
                        $this->view->present($swarm, $context, $agent),
                    );

                    ['output' => $output, 'usage' => $stepUsage] = yield from $this->streamAgentEvents(
                        $agent, $input, $nextIndex, $context, $swarm, $state, $streamSequenceIndex, $streamTelemetryStart, $snapshot,
                    );

                    $durationMs = MonotonicTime::elapsedMilliseconds($stepStartedAt);
                    $this->guardrails->validateStep(
                        $swarm,
                        GuardrailStepContext::fromState($state, $nextIndex, $agent::class, $input, $output, array_merge($node->metadata, ['node_id' => $node->id])),
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
                        metadata: array_merge($node->metadata, ['node_id' => $node->id]),
                    );

                    $nodeOutputs[$node->id] = $output;
                    $executedNodeIds[] = $node->id;
                    $executedAgentClasses[] = $node->agentClass;
                    $mergedUsage = $this->mergeUsage($mergedUsage, $stepUsage);
                    $stepOutput = $step->artifacts[0]->content ?? $this->capture->output($output);

                    $stepEndEvent = new SwarmStepEnd(
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
                    yield $stepEndEvent;
                    $this->recordStreamTelemetry($swarm, $state, $stepEndEvent, $streamSequenceIndex, $streamTelemetryStart, false);

                    $nextIndex++;
                    $currentNodeId = $node->next;

                    continue;
                }

                if ($node instanceof HierarchicalParallelNode) {
                    $executedNodeIds[] = $node->id;
                    $parallelGroups[] = ['node_id' => $node->id, 'branches' => $node->branches];

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
                                input: $this->capture->input($input),
                                timestamp: SwarmStreamEvent::timestamp(),
                            );
                            yield $branchStartEvent;
                            $this->recordStreamTelemetry($swarm, $state, $branchStartEvent, $streamSequenceIndex, $streamTelemetryStart, false);

                            $stepStartedAt = MonotonicTime::now();

                            $snapshot = $this->snapshots->snapshot(
                                $context->runId,
                                $nextIndex,
                                $this->view->present($swarm, $context, $agent),
                            );

                            ['output' => $output, 'usage' => $stepUsage] = yield from $this->streamAgentEvents(
                                $agent, $input, $nextIndex, $context, $swarm, $state, $streamSequenceIndex, $streamTelemetryStart, $snapshot,
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
                                    array_merge($branch->metadata, ['node_id' => $branch->id, 'parent_parallel_node_id' => $node->id]),
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
                                metadata: array_merge($branch->metadata, ['node_id' => $branch->id, 'parent_parallel_node_id' => $node->id]),
                                updateContext: false,
                                storeContext: false,
                            );

                            $nodeOutputs[$branch->id] = $output;
                            $executedNodeIds[] = $branch->id;
                            $executedAgentClasses[] = $branch->agentClass;
                            $mergedUsage = $this->mergeUsage($mergedUsage, $stepUsage);
                            $stepOutput = $step->artifacts[0]->content ?? $this->capture->output($output);

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

                            $branchSnapshots[$ordinal] = $this->snapshots->snapshot(
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
                            $callbacks[$ordinal] = function () use ($agentClass, $input, $branchRunId, $branchSwarmClass, $branchContextPayload): array {
                                $worker = Container::getInstance()->make($agentClass);

                                if (! $worker instanceof Agent) {
                                    throw new SwarmException("Static hierarchical parallel worker [{$agentClass}] must resolve to a Laravel AI agent.");
                                }

                                ActiveRunContext::enter($branchRunId, $branchSwarmClass, RunContext::fromPayload($branchContextPayload, $branchRunId));

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
                                        array_merge($branch->metadata, ['node_id' => $branch->id, 'parent_parallel_node_id' => $node->id]),
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
                            ]);

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
                                output: $step->artifacts[0]->content ?? $this->capture->output($row['output']),
                                durationMs: $row['duration_ms'],
                                metadata: ['usage' => $row['usage']],
                                timestamp: SwarmStreamEvent::timestamp(),
                            );
                            yield $branchEndEvent;
                            $this->recordStreamTelemetry($swarm, $state, $branchEndEvent, $streamSequenceIndex, $streamTelemetryStart, false);
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
                output: $capturedResponse->output,
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
                output: $capturedResponse->output,
                usage: $capturedResponse->usage,
                metadata: $capturedResponse->metadata,
                timestamp: SwarmStreamEvent::timestamp(),
            );
            $this->recordStreamTelemetry($swarm, $state, $streamEndEvent, $streamSequenceIndex, $streamTelemetryStart, false);

            yield $streamEndEvent;

            return $response;
        } catch (Throwable $exception) {
            yield $this->failStream($state, $context, $contextTtl, $swarm, $exception, $startedAt, $streamTelemetryStart, $streamSequenceIndex, $historyRowStarted);

            throw $exception;
        }
    }

    /**
     * Stream events from one agent and yield them as SwarmStreamEvents.
     *
     * Returns the accumulated text output and step usage so the caller can record the step,
     * run guardrails, and emit SwarmStepEnd without duplicating the inner event loop.
     *
     * @return \Generator<int, SwarmStreamEvent, null, array{output: string, usage: array<string, int>}>
     */
    private function streamAgentEvents(
        Agent $agent,
        string $input,
        int $stepIndex,
        RunContext $context,
        Swarm $swarm,
        SwarmExecutionState $state,
        int &$streamSequenceIndex,
        float $streamTelemetryStart,
        MemorySnapshot $snapshot,
    ): \Generator {
        $output = '';
        $stepUsage = [];
        /** @var array<string, ToolCallData> $pendingToolCalls */
        $pendingToolCalls = [];

        // Publish the active run for the agent's RemembersRunContext trait while
        // its stream is consumed; cleared in finally so it never leaks past the
        // streamed step.
        ActiveRunContext::enter($context->runId, $swarm::class, $context);

        try {
            foreach ($agent->stream($input) as $event) {
                if ($event instanceof TextDelta) {
                    $output .= $event->delta;
                    $swarmEvent = new SwarmTextDelta(
                        id: $event->id,
                        runId: $context->runId,
                        stepIndex: $stepIndex,
                        agentClass: $agent::class,
                        delta: $this->capture->output($event->delta),
                        timestamp: $event->timestamp,
                    );
                    $this->syncInvocationId($swarmEvent, $event->invocationId);
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
                    yield $swarmEvent;
                    $this->recordStreamTelemetry($swarm, $state, $swarmEvent, $streamSequenceIndex, $streamTelemetryStart, false);
                } elseif ($event instanceof ReasoningDelta) {
                    $swarmEvent = new SwarmReasoningDelta(
                        id: $event->id,
                        runId: $context->runId,
                        stepIndex: $stepIndex,
                        agentClass: $agent::class,
                        reasoningId: $event->reasoningId,
                        delta: $this->capture->output($event->delta),
                        timestamp: $event->timestamp,
                        summary: $this->captureStaticReasoningSummary($event->summary),
                    );
                    $this->syncInvocationId($swarmEvent, $event->invocationId);
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
                        summary: $this->captureStaticReasoningSummary($event->summary),
                    );
                    $this->syncInvocationId($swarmEvent, $event->invocationId);
                    yield $swarmEvent;
                    $this->recordStreamTelemetry($swarm, $state, $swarmEvent, $streamSequenceIndex, $streamTelemetryStart, false);
                } elseif ($event instanceof ToolCall) {
                    $pendingToolCalls[$event->toolCall->id] = $event->toolCall;

                    $swarmEvent = new SwarmToolCall(
                        id: $event->id,
                        runId: $context->runId,
                        stepIndex: $stepIndex,
                        agentClass: $agent::class,
                        toolCall: $this->captureStaticToolCall($event->toolCall),
                        timestamp: $event->timestamp,
                    );
                    $this->syncInvocationId($swarmEvent, $event->invocationId);
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
                        toolResult: $this->captureStaticToolResult($event->toolResult),
                        successful: $event->successful,
                        error: $this->captureStaticToolError($event->error),
                        timestamp: $event->timestamp,
                    );
                    $this->syncInvocationId($swarmEvent, $event->invocationId);
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
                }
            }

            foreach ($pendingToolCalls as $unpairedCall) {
                $snapshot = $this->snapshots->appendToolCall(
                    $snapshot,
                    SnapshotToolCallNormalizer::entry($unpairedCall),
                );
            }

            return ['output' => $output, 'usage' => $stepUsage];
        } finally {
            ActiveRunContext::exit();
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

    protected function captureStaticToolCall(ToolCallData $toolCall): ToolCallData
    {
        if ($this->capture->capturesOutputs()) {
            return $toolCall;
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

    protected function captureStaticToolResult(ToolResultData $toolResult): ToolResultData
    {
        if ($this->capture->capturesOutputs()) {
            return $toolResult;
        }

        return new ToolResultData(
            id: $toolResult->id,
            name: $toolResult->name,
            arguments: $this->redactStaticArrayPreservingKeys($toolResult->arguments),
            result: $this->redactStaticValue($toolResult->result),
            resultId: $toolResult->resultId,
        );
    }

    protected function captureStaticToolError(?string $error): ?string
    {
        if ($error === null || $this->capture->capturesOutputs()) {
            return $error;
        }

        return '[redacted]';
    }

    /**
     * @param  array<int|string, mixed>|null  $summary
     * @return array<int|string, mixed>|null
     */
    protected function captureStaticReasoningSummary(?array $summary): ?array
    {
        if ($summary === null || $this->capture->capturesOutputs()) {
            return $summary;
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
