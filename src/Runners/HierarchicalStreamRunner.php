<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeChildrenDecided;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeClosed;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeOpened;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Throwable;

/**
 * Streaming execution for Hierarchical swarms (dynamic, coordinator-generated plan).
 *
 * The coordinator implements HasStructuredOutput and cannot be streamed (laravel/ai
 * does not support streaming structured-output agents). It runs synchronously via
 * prompt(), and only the structural causal-log events are emitted for it
 * (SwarmNodeOpened, SwarmStepStart, SwarmStepEnd, SwarmNodeChildrenDecided,
 * SwarmNodeClosed). Worker nodes then stream as normal via the inherited
 * drivePlanNodes() generator starting from step index 1 with `__coordinator__`
 * as the initial parent node.
 *
 * Resolves issue #285.
 *
 * @phpstan-import-type SwarmTaskInput from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 *
 * @internal
 */
class HierarchicalStreamRunner extends StaticHierarchicalStreamRunner
{
    protected const string COORDINATOR_NODE_ID = '__coordinator__';

    /**
     * @param  SwarmTaskInput  $task
     */
    public function stream(Swarm $swarm, string|array|RunContext $task): StreamableSwarmResponse
    {
        $agents = $swarm->agents();

        if ($agents === []) {
            throw new SwarmException('Hierarchical swarms must define at least one agent.');
        }

        /** @var Agent $coordinator */
        $coordinator = array_shift($agents);
        $this->planner->assertCoordinatorCanPlan($coordinator);

        // Build worker map and validate uniqueness (mirrors HierarchicalRunner).
        $workerMap = [];
        foreach ($agents as $agent) {
            $agentClass = $agent::class;
            if (isset($workerMap[$agentClass])) {
                throw new SwarmException(
                    $swarm::class.': agents() contains duplicate agent class '.$agentClass.'. Hierarchical worker classes must be unique.'
                );
            }
            $workerMap[$agentClass] = $agent;
        }

        $topology = Topology::Hierarchical;
        $timeoutSeconds = $this->resolver->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolver->resolveMaxAgentExecutions($swarm);
        $parallelMode = $this->resolver->resolveStreamParallelBranchesForHierarchical($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $context = RunContext::fromTask($task);
        $this->checkInputPayload($task, $context);
        $context->mergeMetadata([
            'swarm_class' => $swarm::class,
            'topology' => $topology->value,
        ]);

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
            generator: function () use ($state, $context, $contextTtl, $swarm, $coordinator, $agents, $workerMap, $parallelMode, &$startedAt): \Generator {
                return yield from $this->executeCoordinatorThenPlan($state, $context, $contextTtl, $swarm, $coordinator, $agents, $workerMap, $parallelMode, $startedAt);
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
     * Stream the coordinator step, parse its plan output, validate the budget,
     * then drive the plan-walk nodes via the inherited drivePlanNodes() generator.
     *
     * @param  array<int, Agent>  $agents
     * @param  array<class-string, Agent>  $workerMap
     * @return \Generator<int, SwarmStreamEvent, null, SwarmResponse>
     */
    protected function executeCoordinatorThenPlan(
        SwarmExecutionState $state,
        RunContext $context,
        int $contextTtl,
        Swarm $swarm,
        Agent $coordinator,
        array $agents,
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
            // ----------------------------------------------------------------
            // Coordinator step — opens the __coordinator__ node, streams the
            // coordinator's deliberation, then emits children-decided + closed.
            // ----------------------------------------------------------------

            $nodeOpenedEvent = (new SwarmNodeOpened(
                id: static::COORDINATOR_NODE_ID,
                runId: $context->runId,
                parentNodeId: null,
                role: 'coordinator',
                rationale: null,
                timestamp: SwarmStreamEvent::timestamp(),
            ))->withNodeId(static::COORDINATOR_NODE_ID);
            yield $nodeOpenedEvent;
            $this->recordStreamTelemetry($swarm, $state, $nodeOpenedEvent, $streamSequenceIndex, $streamTelemetryStart, false);

            $coordinatorName = class_basename($coordinator::class);
            $coordinatorStepMetadata = ['node_role' => 'coordinator', 'node_id' => static::COORDINATOR_NODE_ID];

            $this->stepsRecorder->started($state, 0, $coordinator::class, $context->input);

            $coordinatorStepStartEvent = (new SwarmStepStart(
                id: SwarmStreamEvent::newId(),
                runId: $context->runId,
                stepIndex: 0,
                agentClass: $coordinator::class,
                agent: $coordinatorName,
                input: $this->capture->applyInput($context->input, $context),
                timestamp: SwarmStreamEvent::timestamp(),
            ))->withNodeId(static::COORDINATOR_NODE_ID);
            yield $coordinatorStepStartEvent;
            $this->recordStreamTelemetry($swarm, $state, $coordinatorStepStartEvent, $streamSequenceIndex, $streamTelemetryStart, false);

            $stepStartedAt = MonotonicTime::now();

            // The coordinator implements HasStructuredOutput: laravel/ai does not
            // support streaming structured-output agents. Run it synchronously.
            $this->snapshots->snapshot(
                $context->runId,
                0,
                $this->view->present($swarm, $context, $coordinator),
            );

            ActiveRunContext::enter($context->runId, $swarm::class, $context);

            try {
                $coordinatorResponse = $coordinator->prompt($context->input);
            } finally {
                ActiveRunContext::exit();
            }

            $coordinatorOutput = (string) $coordinatorResponse;
            $coordinatorUsage = $this->usageFromResponse($coordinatorResponse);

            $coordinatorDurationMs = MonotonicTime::elapsedMilliseconds($stepStartedAt);

            $this->guardrails->validateStep(
                $swarm,
                GuardrailStepContext::fromState($state, 0, $coordinator::class, $context->input, $coordinatorOutput, $coordinatorStepMetadata),
                $context,
            );

            $coordinatorStep = $this->stepsRecorder->completed(
                state: $state,
                index: 0,
                agentClass: $coordinator::class,
                input: $context->input,
                output: $coordinatorOutput,
                usage: $coordinatorUsage,
                durationMs: $coordinatorDurationMs,
                metadata: $coordinatorStepMetadata,
            );

            $coordinatorStepOutput = $this->capture->applyOutput((string) ($coordinatorStep->artifacts[0]->content ?? $coordinatorOutput), $context);

            $coordinatorStepEndEvent = (new SwarmStepEnd(
                id: SwarmStreamEvent::newId(),
                runId: $context->runId,
                stepIndex: 0,
                agentClass: $coordinator::class,
                agent: $coordinatorName,
                output: $coordinatorStepOutput,
                durationMs: $coordinatorDurationMs,
                metadata: ['usage' => $coordinatorUsage],
                timestamp: SwarmStreamEvent::timestamp(),
            ))->withNodeId(static::COORDINATOR_NODE_ID);
            yield $coordinatorStepEndEvent;
            $this->recordStreamTelemetry($swarm, $state, $coordinatorStepEndEvent, $streamSequenceIndex, $streamTelemetryStart, false);

            // Parse the coordinator's output into a route plan.
            $plan = $this->planner->fromCoordinatorOutput($coordinator, $agents, $coordinatorOutput, $swarm::class);

            // Budget check: coordinator (1) + all reachable workers.
            $required = 1 + $plan->reachableWorkerCount();
            if ($required > $state->maxAgentExecutions) {
                throw new SwarmException(sprintf(
                    "%s: hierarchical route plan requires %d agent executions but the swarm allows %d. Increase #[MaxAgentSteps] or reduce the plan's worker nodes.",
                    $swarm::class,
                    $required,
                    $state->maxAgentExecutions,
                ));
            }

            // Declare the plan's start node as the coordinator's decided child.
            $childrenDecidedEvent = (new SwarmNodeChildrenDecided(
                id: SwarmStreamEvent::newId(),
                runId: $context->runId,
                childNodeIds: [$plan->startAt],
                rationale: null,
                timestamp: SwarmStreamEvent::timestamp(),
            ))->withNodeId(static::COORDINATOR_NODE_ID);
            yield $childrenDecidedEvent;
            $this->recordStreamTelemetry($swarm, $state, $childrenDecidedEvent, $streamSequenceIndex, $streamTelemetryStart, false);

            // Close the coordinator node — every event tagged __coordinator__ is now bracketed.
            $coordinatorClosedEvent = (new SwarmNodeClosed(
                id: SwarmStreamEvent::newId(),
                runId: $context->runId,
                result: $this->capture->applyOutput($coordinatorOutput, $context),
                timestamp: SwarmStreamEvent::timestamp(),
            ))->withNodeId(static::COORDINATOR_NODE_ID);
            yield $coordinatorClosedEvent;
            $this->recordStreamTelemetry($swarm, $state, $coordinatorClosedEvent, $streamSequenceIndex, $streamTelemetryStart, false);

            // ----------------------------------------------------------------
            // Plan-walk — drive from step index 1, with __coordinator__ as the
            // initial parent so the first worker node opens under it.
            // ----------------------------------------------------------------

            ['mergedUsage' => $walkUsage, 'executedNodeIds' => $executedNodeIds, 'executedAgentClasses' => $executedAgentClasses, 'parallelGroups' => $parallelGroups, 'nextIndex' => $nextIndex]
                = yield from $this->drivePlanNodes(
                    state: $state,
                    context: $context,
                    contextTtl: $contextTtl,
                    swarm: $swarm,
                    plan: $plan,
                    workerMap: $workerMap,
                    parallelMode: $parallelMode,
                    nextIndex: 1,
                    initialParentNodeId: static::COORDINATOR_NODE_ID,
                    streamSequenceIndex: $streamSequenceIndex,
                    streamTelemetryStart: $streamTelemetryStart,
                );

            $mergedUsage = $this->mergeUsage($coordinatorUsage, $walkUsage);

            $context->mergeMetadata([
                'usage' => $mergedUsage,
                'coordinator_agent_class' => $coordinator::class,
                'route_plan_start' => $plan->startAt,
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
                    'coordinator_agent_class' => $coordinator::class,
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

            return $response;
        } catch (Throwable $exception) {
            yield $this->failStream($state, $context, $contextTtl, $swarm, $exception, $startedAt, $streamTelemetryStart, $streamSequenceIndex, $historyRowStarted);

            throw $exception;
        }
    }
}
