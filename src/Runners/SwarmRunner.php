<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Audit\RunAuditEmitter;
use BuiltByBerry\LaravelSwarm\Contracts\ActorResolver;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ClaimsQueuedRunExecution;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\HaltsSwarmExecution;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\MissingActorException;
use BuiltByBerry\LaravelSwarm\Exceptions\MissingQueueLeaseSchemaException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Broadcasting\Channel;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Bus\PendingDispatch;
use Throwable;

/**
 * Orchestrates a swarm run from public entry point through topology dispatch to terminal persistence.
 *
 * SwarmRunner is intentionally a flow-control class. Dispatch-time validation,
 * lease lifecycle, and run-level audit emission are delegated to focused
 * collaborators (DispatchValidator, LeaseManager, RunAuditEmitter) so the
 * orchestrator can stay readable.
 *
 * @phpstan-import-type SwarmTaskInput from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 *
 * @internal
 */
class SwarmRunner
{
    public function __construct(
        protected ConfigRepository $config,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected RunHistoryStore $historyStore,
        protected StreamEventStore $streamEvents,
        protected DurableRunStore $durableRuns,
        protected Dispatcher $events,
        protected SequentialRunner $sequential,
        protected SequentialStreamRunner $sequentialStream,
        protected ParallelRunner $parallel,
        protected HierarchicalRunner $hierarchical,
        protected StaticHierarchicalRunner $staticHierarchical,
        protected StaticHierarchicalStreamRunner $staticHierarchicalStream,
        protected HierarchicalStreamRunner $hierarchicalStream,
        protected DurableSwarmManager $durable,
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
        protected SwarmAttributeResolver $resolver,
        protected QueuedHierarchicalCoordinator $queuedHierarchicalCoordinator,
        protected SwarmGuardrailRunner $guardrails,
        protected ActorResolver $actorResolver,
        protected RunAuditEmitter $auditEmitter,
        protected DispatchValidator $validator,
        protected LeaseManager $leases,
    ) {}

    /**
     * Resolve the shared Swarm memory facade.
     *
     * Equivalent to `app(SwarmMemory::class)`; exposed here so the existing
     * `Swarm::memory()` helper (via the facade `@mixin`) works consistently
     * with the rest of the runtime accessor surface.
     */
    public function memory(): SwarmMemory
    {
        return Container::getInstance()->make(SwarmMemory::class);
    }

    /**
     * @param  SwarmTaskInput  $task
     */
    public function run(Swarm $swarm, string|array|RunContext $task): SwarmResponse
    {
        return $this->runWithExecutionMode($swarm, $task, ExecutionMode::Run);
    }

    /**
     * @param  SwarmTaskInput  $task
     */
    public function runQueued(Swarm $swarm, string|array|RunContext $task): ?SwarmResponse
    {
        return $this->runWithExecutionMode($swarm, $task, ExecutionMode::Queue);
    }

    /**
     * @param  SwarmTaskInput  $task
     */
    protected function runWithExecutionMode(Swarm $swarm, string|array|RunContext $task, ExecutionMode $executionMode): ?SwarmResponse
    {
        $startedAt = MonotonicTime::now();
        $topology = $this->resolver->resolveTopology($swarm);
        $this->validator->ensureSwarmHasAgents($swarm);
        $timeoutSeconds = $this->resolver->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolver->resolveMaxAgentExecutions($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $queueLeaseSeconds = $executionMode === ExecutionMode::Queue
            ? $this->leases->resolveQueueLeaseSeconds($timeoutSeconds)
            : null;
        $context = RunContext::fromTask($task);
        $this->validator->checkInputPayload($task, $context, $executionMode);
        $this->validator->ensureActiveContextCompatible($executionMode);
        $context->mergeMetadata([
            'swarm_class' => $swarm::class,
            'topology' => $topology->value,
        ]);
        $this->resolveActor($context);

        $queueHierarchicalCoord = null;

        if ($executionMode === ExecutionMode::Queue && $topology === Topology::Hierarchical) {
            $queueHierarchicalCoord = $this->resolver->resolveQueueHierarchicalParallelCoordination($swarm);

            if ($queueHierarchicalCoord === 'multi_worker') {
                $this->validator->ensureDatabaseDurableInfrastructure();
                $context->mergeMetadata([
                    'durable_parallel_failure_policy' => $this->resolver->resolveDurableParallelFailurePolicy($swarm)->value,
                ]);
            }
        }

        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: $topology,
            executionMode: $executionMode,
            deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: $maxAgentExecutions,
            ttlSeconds: $contextTtl,
            leaseSeconds: $queueLeaseSeconds,
            executionToken: null,
            verifyOwnership: null,
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
            queueHierarchicalParallelCoordination: $queueHierarchicalCoord,
        );

        $historyStarted = false;

        try {
            $this->guardrails->validateInput($swarm, $context);

            if ($executionMode === ExecutionMode::Queue && $this->historyStore instanceof ClaimsQueuedRunExecution) {
                $acquisition = $this->historyStore->acquireQueuedRun(
                    $context->runId,
                    $swarm::class,
                    $topology->value,
                    $this->capture->context($context),
                    $context->metadata,
                    $contextTtl,
                    $queueLeaseSeconds ?? $timeoutSeconds,
                );

                if (! $acquisition->acquired()) {
                    return null;
                }

                $historyStarted = true;

                $state = new SwarmExecutionState(
                    swarm: $swarm,
                    topology: $topology,
                    executionMode: $executionMode,
                    deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
                    maxAgentExecutions: $maxAgentExecutions,
                    ttlSeconds: $contextTtl,
                    leaseSeconds: $queueLeaseSeconds,
                    executionToken: $acquisition->executionToken,
                    verifyOwnership: null,
                    context: $context,
                    contextStore: $this->contextStore,
                    artifactRepository: $this->artifactRepository,
                    historyStore: $this->historyStore,
                    events: $this->events,
                    queueHierarchicalParallelCoordination: $queueHierarchicalCoord,
                );
            } else {
                $this->historyStore->start($context->runId, $swarm::class, $topology->value, $this->capture->context($context), $context->metadata, $contextTtl);
                $historyStarted = true;
            }

            $this->contextStore->put($this->capture->activeContext($context), $contextTtl);
            $this->events->dispatch(new SwarmStarted(
                runId: $context->runId,
                swarmClass: $swarm::class,
                topology: $topology->value,
                input: $this->capture->applyInput($context->input, $context),
                metadata: $context->metadata,
                executionMode: $executionMode->value,
            ));
            $this->auditEmitter->emitRunStarted($context, $swarm::class, $topology, $executionMode);

            $response = match ($topology) {
                Topology::Sequential => $this->sequential->run($state),
                Topology::Parallel => $this->parallel->run($state),
                Topology::Hierarchical => ($executionMode === ExecutionMode::Queue && $queueHierarchicalCoord === 'multi_worker')
                    ? $this->queuedHierarchicalCoordinator->runInvokeSegment($state)
                    : $this->hierarchical->run($state),
                Topology::StaticHierarchical => $this->staticHierarchical->run($state),
            };

            if ($response === null) {
                return null;
            }

            return $this->finalizeSuccessfulSwarmExecution($state, $swarm, $topology, $executionMode, $response, $startedAt, $contextTtl);
        } catch (MissingQueueLeaseSchemaException $e) {
            throw $e;
        } catch (LostSwarmLeaseException) {
            return null;
        } catch (Throwable $exception) {
            if ($exception instanceof GuardrailViolation) {
                $context->mergeMetadata($exception->safeContextMetadata());
            }

            try {
                if ($historyStarted) {
                    $this->historyStore->fail($context->runId, $exception, $contextTtl, $state->executionToken, $state->leaseSeconds);
                } else {
                    $this->historyStore->recordPreflightFailure(
                        $context->runId,
                        $swarm::class,
                        $topology->value,
                        $context,
                        $context->metadata,
                        $exception,
                        $contextTtl,
                    );
                }
            } catch (LostSwarmLeaseException) {
                return null;
            }

            $this->contextStore->put($this->capture->terminalContext($context), $contextTtl);

            $this->events->dispatch(new SwarmFailed(
                runId: $context->runId,
                swarmClass: $swarm::class,
                topology: $topology->value,
                exception: $this->capture->failureException($exception),
                durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
                metadata: $context->metadata,
                executionMode: $executionMode->value,
                exceptionClass: $exception::class,
            ));

            // Skip the run.failed audit emit when the cause was an audit halt —
            // re-emitting through the same dispatcher would just halt again and
            // mask the original sink failure with a duplicate halt exception.
            if (! $exception instanceof HaltsSwarmExecution) {
                $this->auditEmitter->emitRunFailed(
                    $context,
                    $swarm::class,
                    $topology,
                    $executionMode,
                    $exception,
                    MonotonicTime::elapsedMilliseconds($startedAt),
                );
            }

            throw $exception;
        }
    }

    /**
     * @param  SwarmTaskInput  $task
     */
    public function stream(Swarm $swarm, string|array|RunContext $task): StreamableSwarmResponse
    {
        $topology = $this->resolver->resolveTopology($swarm);
        $context = RunContext::fromTask($task);
        $this->resolveActor($context);

        if ($topology === Topology::StaticHierarchical) {
            return $this->staticHierarchicalStream->stream($swarm, $context);
        }

        if ($topology === Topology::Hierarchical) {
            return $this->hierarchicalStream->stream($swarm, $context);
        }

        $this->validator->ensureStreamableTopology($swarm);

        return $this->sequentialStream->stream($swarm, $context);
    }

    /**
     * @param  SwarmTaskInput  $task
     * @param  SwarmBroadcastChannels  $channels
     */
    public function broadcast(Swarm $swarm, string|array|RunContext $task, Channel|array $channels, bool $now = false): StreamableSwarmResponse
    {
        return $this->stream($swarm, $task)
            ->each(function (SwarmStreamEvent $event) use ($channels, $now): void {
                $event->{$now ? 'broadcastNow' : 'broadcast'}($channels);
            });
    }

    /**
     * @param  SwarmTaskInput  $task
     */
    public function queue(Swarm $swarm, string|array|RunContext $task): QueuedSwarmResponse
    {
        $this->validator->validateForDispatch($swarm);
        $this->validator->ensureQueueable($swarm);
        $this->validator->ensureContainerResolvable($swarm);

        $context = RunContext::fromTask($task);
        $this->validator->checkInputPayload($task, $context, ExecutionMode::Queue);
        $this->validator->ensureActiveContextCompatible(ExecutionMode::Queue);
        $this->resolveActor($context);
        try {
            $this->guardrails->validateInput($swarm, $context);
        } catch (GuardrailViolation $e) {
            $this->recordDispatchPreflightFailure($swarm, $context, ExecutionMode::Queue, $e);
            throw $e;
        }

        $pendingDispatch = new PendingDispatch(new InvokeSwarm($swarm::class, $context->toQueuePayload()));

        if ($connection = $this->config->get('swarm.queue.connection')) {
            $pendingDispatch->onConnection($connection);
        }

        if ($name = $this->config->get('swarm.queue.name')) {
            $pendingDispatch->onQueue($name);
        }

        return new QueuedSwarmResponse($pendingDispatch, $context->runId);
    }

    /**
     * @param  SwarmTaskInput  $task
     * @param  SwarmBroadcastChannels  $channels
     */
    public function broadcastOnQueue(Swarm $swarm, string|array|RunContext $task, Channel|array $channels): QueuedSwarmResponse
    {
        $this->validator->ensureStreamableTopology($swarm);
        $this->validator->validateForDispatch($swarm);
        $this->validator->ensureQueueable($swarm);
        $this->validator->ensureContainerResolvable($swarm);

        $context = RunContext::fromTask($task);
        $this->validator->checkInputPayload($task, $context, ExecutionMode::Queue);
        $this->validator->ensureActiveContextCompatible(ExecutionMode::Queue);
        $this->resolveActor($context);
        try {
            $this->guardrails->validateInput($swarm, $context);
        } catch (GuardrailViolation $e) {
            $this->recordDispatchPreflightFailure($swarm, $context, ExecutionMode::Queue, $e);
            throw $e;
        }

        $pendingDispatch = new PendingDispatch(new BroadcastSwarm($swarm::class, $context->toQueuePayload(), $channels));

        if ($connection = $this->config->get('swarm.queue.connection')) {
            $pendingDispatch->onConnection($connection);
        }

        if ($name = $this->config->get('swarm.queue.name')) {
            $pendingDispatch->onQueue($name);
        }

        return new QueuedSwarmResponse($pendingDispatch, $context->runId);
    }

    /**
     * @param  SwarmTaskInput  $task
     */
    public function dispatchDurable(Swarm $swarm, string|array|RunContext $task): DurableSwarmResponse
    {
        $this->validator->ensureSwarmHasAgents($swarm);
        $this->validator->ensureQueueable($swarm);
        $this->validator->ensureContainerResolvable($swarm);

        $topology = $this->resolver->resolveTopology($swarm);

        $this->validator->ensureDatabaseDurableInfrastructure();

        if ($topology === Topology::Parallel) {
            $this->parallel->ensureAgentsAreContainerResolvable($swarm->agents(), $swarm::class);
        }

        if ($topology === Topology::Hierarchical) {
            $this->hierarchical->ensureUniqueWorkerClassesForSwarm($swarm);
        }

        if ($topology === Topology::StaticHierarchical) {
            $this->staticHierarchical->buildPlanForSwarm($swarm);
        }

        $timeoutSeconds = $this->resolver->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolver->resolveMaxAgentExecutions($swarm);
        $totalSteps = in_array($topology, [Topology::Hierarchical, Topology::StaticHierarchical], true)
            ? $maxAgentExecutions
            : min(count($swarm->agents()), $maxAgentExecutions);
        $context = RunContext::fromTask($task);
        $this->validator->checkInputPayload($task, $context, ExecutionMode::Durable);
        $this->validator->ensureActiveContextCompatible(ExecutionMode::Durable);
        $this->resolveActor($context);

        try {
            $this->guardrails->validateInput($swarm, $context);
        } catch (GuardrailViolation $e) {
            $this->recordDispatchPreflightFailure($swarm, $context, ExecutionMode::Durable, $e);
            throw $e;
        }

        $start = $this->durable->start($swarm, $context, $topology, $timeoutSeconds, $totalSteps, $this->resolver->resolveDurableParallelFailurePolicy($swarm));

        return new DurableSwarmResponse(new PendingDispatch($start->job), $this->durable, $start->runId);
    }

    /**
     * Persist a preflight failure row, dispatch SwarmFailed, and emit audit for dispatch-time guardrail blocks
     * (queue, broadcastOnQueue, dispatchDurable). These paths never reach runWithExecutionMode, so the
     * normal failure handler in that method cannot run.
     */
    protected function recordDispatchPreflightFailure(Swarm $swarm, RunContext $context, ExecutionMode $executionMode, GuardrailViolation $violation): void
    {
        $topology = $this->resolver->resolveTopology($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $context->mergeMetadata($violation->safeContextMetadata());

        $this->historyStore->recordPreflightFailure(
            $context->runId,
            $swarm::class,
            $topology->value,
            $context,
            $context->metadata,
            $violation,
            $contextTtl,
        );

        $this->events->dispatch(new SwarmFailed(
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $topology->value,
            exception: $this->capture->failureException($violation),
            durationMs: 0,
            metadata: $context->metadata,
            executionMode: $executionMode->value,
            exceptionClass: $violation::class,
        ));

        $this->auditEmitter->emitRunFailed($context, $swarm::class, $topology, $executionMode, $violation, 0);
    }

    protected function normalizeCompletionResponse(SwarmResponse $response, RunContext $context, string $topology): SwarmResponse
    {
        return new SwarmResponse(
            output: $response->output,
            steps: $response->steps,
            usage: $response->usage,
            context: $context,
            artifacts: $response->artifacts,
            metadata: array_merge(
                $context->metadata,
                $response->metadata,
                [
                    'run_id' => $context->runId,
                    'topology' => $topology,
                ],
            ),
        );
    }

    /**
     * Resolve the actor for this run when none is bound via RunContext::withActor.
     *
     * Honors a pre-bound actor untouched. Otherwise calls ActorResolver::resolve()
     * and stores the result under metadata.actor. When swarm.audit.actor.required
     * is true and resolution yields no actor, throws MissingActorException.
     */
    protected function resolveActor(RunContext $context): void
    {
        $existing = $context->metadata['actor'] ?? null;

        if (is_array($existing) && $existing !== []) {
            return;
        }

        $resolved = $this->actorResolver->resolve();

        if ($resolved !== null) {
            $context->mergeMetadata(['actor' => $resolved->toArray()]);

            return;
        }

        if ((bool) $this->config->get('swarm.audit.actor.required', false)) {
            throw new MissingActorException(
                'No actor could be resolved for this swarm run. Bind one via $context->withActor(...) before dispatch, set Context::add(\'swarm:actor\', ...) inside a request boundary, or set swarm.audit.actor.required=false to allow anonymous runs.'
            );
        }
    }

    public function resumeQueuedHierarchicalAfterJoin(string $runId): ?SwarmResponse
    {
        $startedAt = MonotonicTime::now();
        $durableRun = $this->durableRuns->find($runId);

        if ($durableRun === null || (($durableRun['coordination_profile'] ?? '') !== CoordinationProfile::QueueHierarchicalParallel->value)) {
            return null;
        }

        if (! $this->historyStore instanceof ClaimsQueuedRunExecution) {
            return null;
        }

        $swarm = Container::getInstance()->make($durableRun['swarm_class']);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve queued hierarchical swarm [{$durableRun['swarm_class']}] from the container.");
        }

        $payload = $this->contextStore->find($runId);

        if ($payload === null) {
            throw new SwarmException("Swarm run [{$runId}] is missing persisted context.");
        }

        $context = RunContext::fromPayload($payload);
        $topology = Topology::Hierarchical;
        $timeoutSeconds = $this->resolver->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolver->resolveMaxAgentExecutions($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $queueLeaseSeconds = $this->leases->resolveQueueLeaseSeconds($timeoutSeconds);
        $acquisition = $this->historyStore->acquireQueuedRunContinuationLease($runId, $contextTtl, $queueLeaseSeconds);

        if (! $acquisition->acquired()) {
            return null;
        }

        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: $topology,
            executionMode: ExecutionMode::Queue,
            deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: $maxAgentExecutions,
            ttlSeconds: $contextTtl,
            leaseSeconds: $queueLeaseSeconds,
            executionToken: $acquisition->executionToken,
            verifyOwnership: null,
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
            queueHierarchicalParallelCoordination: 'multi_worker',
        );

        try {
            $outcome = $this->hierarchical->continueQueuedAfterParallelJoin($state, $durableRun);
        } catch (LostSwarmLeaseException) {
            return null;
        } catch (Throwable $exception) {
            $this->handleResumeFailure($state, $swarm, $topology, $context, $exception, $startedAt, $contextTtl, $runId);
            throw $exception;
        }

        if ($outcome instanceof QueueHierarchicalParallelBoundary) {
            // The boundary's branch fan-out is enqueued inside enter()'s DB transaction,
            // ordered after a token-guarded syncDurableState write. If this worker's
            // continuation lease was reclaimed (its token rotated), that guarded write
            // fails, the whole transaction rolls back — no branches dispatched — and we
            // bail here, mirroring the LostSwarmLeaseException handling above. This is the
            // F-D1 guarantee for the deferral path: a reclaim never double-dispatches.
            try {
                $this->durable->enterQueueHierarchicalParallelCoordination($state, $outcome);
            } catch (LostSwarmLeaseException) {
                return null;
            }

            return null;
        }

        $this->leases->markCoordinationRunCompleted($runId);

        try {
            return $this->finalizeSuccessfulSwarmExecution($state, $swarm, $topology, ExecutionMode::Queue, $outcome, $startedAt, $contextTtl);
        } catch (Throwable $exception) {
            if ($exception instanceof GuardrailViolation) {
                $context->mergeMetadata($exception->safeContextMetadata());
            }

            $this->handleResumeFailure($state, $swarm, $topology, $context, $exception, $startedAt, $contextTtl, $runId);
            throw $exception;
        }
    }

    /**
     * Shared failure path for resumeQueuedHierarchicalAfterJoin — persist history failure,
     * write terminal context, fail the coordination row, and emit SwarmFailed + audit.
     *
     * Re-raise is the caller's responsibility so the original exception still surfaces.
     * Returns silently on LostSwarmLeaseException so the caller can decide to swallow.
     */
    protected function handleResumeFailure(
        SwarmExecutionState $state,
        Swarm $swarm,
        Topology $topology,
        RunContext $context,
        Throwable $exception,
        float $startedAt,
        int $contextTtl,
        string $runId,
    ): void {
        try {
            $this->historyStore->fail($context->runId, $exception, $contextTtl, $state->executionToken, $state->leaseSeconds);
        } catch (LostSwarmLeaseException) {
            return;
        }

        $this->contextStore->put($this->capture->terminalContext($context), $contextTtl);
        $this->leases->failCoordinationRunIfQueueHierarchicalParallel($runId, $exception);

        $this->events->dispatch(new SwarmFailed(
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $topology->value,
            exception: $this->capture->failureException($exception),
            durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
            metadata: $context->metadata,
            executionMode: ExecutionMode::Queue->value,
            exceptionClass: $exception::class,
        ));
        $this->auditEmitter->emitRunFailed(
            $context,
            $swarm::class,
            $topology,
            ExecutionMode::Queue,
            $exception,
            MonotonicTime::elapsedMilliseconds($startedAt),
        );
    }

    /**
     * Normalize capture limits, persist terminal history, terminal context, and emit SwarmCompleted.
     *
     * @return SwarmResponse|null null when the queued execution lease was lost during completion.
     */
    protected function finalizeSuccessfulSwarmExecution(
        SwarmExecutionState $state,
        Swarm $swarm,
        Topology $topology,
        ExecutionMode $executionMode,
        SwarmResponse $response,
        float $startedAt,
        int $contextTtl,
    ): ?SwarmResponse {
        $context = $state->context;
        $this->guardrails->validateOutput($state->swarm, $context, $response->output);
        $response = $this->normalizeCompletionResponse($response, $context, $topology->value);
        $capturedResponse = $this->limits->response($this->capture->response($response));

        try {
            $this->historyStore->complete($context->runId, $capturedResponse, $contextTtl, $state->executionToken, $state->leaseSeconds);
        } catch (LostSwarmLeaseException) {
            return null;
        }

        $this->contextStore->put($this->capture->terminalContext($context), $contextTtl);
        $this->events->dispatch(new SwarmCompleted(
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $topology->value,
            output: $this->capture->applyOutput($capturedResponse->output, $context),
            durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
            metadata: $capturedResponse->metadata,
            artifacts: $capturedResponse->artifacts,
            executionMode: $executionMode->value,
        ));
        $this->auditEmitter->emitRunCompleted(
            $context,
            $swarm::class,
            $topology,
            $executionMode,
            MonotonicTime::elapsedMilliseconds($startedAt),
            $capturedResponse->metadata,
        );

        return $response;
    }
}
