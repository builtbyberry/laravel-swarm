<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ClaimsQueuedRunExecution;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
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
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Bus\PendingDispatch;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

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
        protected DurableSwarmManager $durable,
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
        protected SwarmAttributeResolver $resolver,
    ) {}

    public function run(Swarm $swarm, string|array|RunContext $task): SwarmResponse
    {
        return $this->runWithExecutionMode($swarm, $task, ExecutionMode::Run);
    }

    public function runQueued(Swarm $swarm, string|array|RunContext $task): ?SwarmResponse
    {
        return $this->runWithExecutionMode($swarm, $task, ExecutionMode::Queue);
    }

    protected function runWithExecutionMode(Swarm $swarm, string|array|RunContext $task, ExecutionMode $executionMode): ?SwarmResponse
    {
        $startedAt = MonotonicTime::now();
        $topology = $this->resolver->resolveTopology($swarm);
        $this->ensureSwarmHasAgents($swarm);
        $timeoutSeconds = $this->resolver->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolver->resolveMaxAgentExecutions($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $queueLeaseSeconds = $executionMode === ExecutionMode::Queue
            ? $this->resolveQueueLeaseSeconds($timeoutSeconds)
            : null;
        $context = RunContext::fromTask($task);
        $this->checkInputPayload($task, $context, $executionMode);
        $this->ensureActiveContextCompatible($executionMode);
        $context->mergeMetadata([
            'swarm_class' => $swarm::class,
            'topology' => $topology->value,
        ]);

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
        );

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
            );
        } else {
            $this->historyStore->start($context->runId, $swarm::class, $topology->value, $this->capture->context($context), $context->metadata, $contextTtl);
        }

        $this->contextStore->put($this->capture->activeContext($context), $contextTtl);
        $this->events->dispatch(new SwarmStarted(
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $topology->value,
            input: $this->capture->input($context->input),
            metadata: $context->metadata,
            executionMode: $executionMode->value,
        ));

        try {
            $response = match ($topology) {
                Topology::Sequential => $this->sequential->run($state),
                Topology::Parallel => $this->parallel->run($state),
                Topology::Hierarchical => $this->hierarchical->run($state),
            };
        } catch (LostSwarmLeaseException) {
            return null;
        } catch (Throwable $exception) {
            try {
                $this->historyStore->fail($context->runId, $exception, $contextTtl, $state->executionToken, $state->leaseSeconds);
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

            throw $exception;
        }

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
            output: $capturedResponse->output,
            durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
            metadata: $capturedResponse->metadata,
            artifacts: $capturedResponse->artifacts,
            executionMode: $executionMode->value,
        ));

        return $response;
    }

    public function stream(Swarm $swarm, string|array|RunContext $task): StreamableSwarmResponse
    {
        return $this->sequentialStream->stream($swarm, $task);
    }

    public function broadcast(Swarm $swarm, string|array|RunContext $task, Channel|array $channels, bool $now = false): StreamableSwarmResponse
    {
        return $this->stream($swarm, $task)
            ->each(function (SwarmStreamEvent $event) use ($channels, $now): void {
                $event->{$now ? 'broadcastNow' : 'broadcast'}($channels);
            });
    }

    public function queue(Swarm $swarm, string|array|RunContext $task): QueuedSwarmResponse
    {
        $this->validateForDispatch($swarm);
        $this->ensureQueueable($swarm);
        $this->ensureContainerResolvable($swarm);

        $context = RunContext::fromTask($task);
        $this->checkInputPayload($task, $context, ExecutionMode::Queue);
        $this->ensureActiveContextCompatible(ExecutionMode::Queue);
        $pendingDispatch = new PendingDispatch(new InvokeSwarm($swarm::class, $context->toQueuePayload()));

        if ($connection = $this->config->get('swarm.queue.connection')) {
            $pendingDispatch->onConnection($connection);
        }

        if ($name = $this->config->get('swarm.queue.name')) {
            $pendingDispatch->onQueue($name);
        }

        return new QueuedSwarmResponse($pendingDispatch, $context->runId);
    }

    public function broadcastOnQueue(Swarm $swarm, string|array|RunContext $task, Channel|array $channels): QueuedSwarmResponse
    {
        $this->ensureStreamableTopology($swarm);
        $this->validateForDispatch($swarm);
        $this->ensureQueueable($swarm);
        $this->ensureContainerResolvable($swarm);

        $context = RunContext::fromTask($task);
        $this->checkInputPayload($task, $context, ExecutionMode::Queue);
        $this->ensureActiveContextCompatible(ExecutionMode::Queue);
        $pendingDispatch = new PendingDispatch(new BroadcastSwarm($swarm::class, $context->toQueuePayload(), $channels));

        if ($connection = $this->config->get('swarm.queue.connection')) {
            $pendingDispatch->onConnection($connection);
        }

        if ($name = $this->config->get('swarm.queue.name')) {
            $pendingDispatch->onQueue($name);
        }

        return new QueuedSwarmResponse($pendingDispatch, $context->runId);
    }

    public function dispatchDurable(Swarm $swarm, string|array|RunContext $task): DurableSwarmResponse
    {
        $this->ensureSwarmHasAgents($swarm);
        $this->ensureQueueable($swarm);
        $this->ensureContainerResolvable($swarm);
        $this->ensureDatabaseDurableInfrastructure();

        $topology = $this->resolver->resolveTopology($swarm);
        if ($topology === Topology::Parallel) {
            $this->parallel->ensureAgentsAreContainerResolvable($swarm->agents(), $swarm::class);
        }

        if ($topology === Topology::Hierarchical) {
            $this->hierarchical->ensureUniqueWorkerClassesForSwarm($swarm);
        }

        $timeoutSeconds = $this->resolver->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolver->resolveMaxAgentExecutions($swarm);
        $totalSteps = $topology === Topology::Hierarchical
            ? $maxAgentExecutions
            : min(count($swarm->agents()), $maxAgentExecutions);
        $context = RunContext::fromTask($task);
        $this->checkInputPayload($task, $context, ExecutionMode::Durable);
        $this->ensureActiveContextCompatible(ExecutionMode::Durable);
        $start = $this->durable->start($swarm, $context, $topology, $timeoutSeconds, $totalSteps, $this->resolver->resolveDurableParallelFailurePolicy($swarm));

        return new DurableSwarmResponse(new PendingDispatch($start->job), $this->durable, $start->runId);
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

    protected function resolveQueueLeaseSeconds(int $timeoutSeconds): int
    {
        return max($timeoutSeconds * 2, 300);
    }

    protected function checkInputPayload(string|array|RunContext $task, RunContext $context, ExecutionMode $executionMode): void
    {
        if ($task instanceof RunContext || in_array($executionMode, [ExecutionMode::Queue, ExecutionMode::Durable], true)) {
            $this->limits->checkContextInput($context);

            return;
        }

        $this->limits->checkInput($context->input);
    }

    protected function ensureActiveContextCompatible(ExecutionMode $executionMode): void
    {
        if ($this->capture->capturesActiveContext()) {
            return;
        }

        if (in_array($executionMode, [ExecutionMode::Queue, ExecutionMode::Durable], true)) {
            throw new SwarmException('Queued and durable swarms require active runtime context persistence so workers can continue or recover the run. Enable [swarm.capture.active_context] or use synchronous execution.');
        }
    }

    protected function ensureSwarmHasAgents(Swarm $swarm): void
    {
        if ($swarm->agents() !== []) {
            return;
        }

        throw new SwarmException(class_basename($swarm).': swarm has no agents. Add at least one agent to agents().');
    }

    protected function validateForDispatch(Swarm $swarm): void
    {
        $topology = $this->resolver->resolveTopology($swarm);
        $this->ensureSwarmHasAgents($swarm);
        $this->resolver->resolveTimeoutSeconds($swarm);
        $this->resolver->resolveMaxAgentExecutions($swarm);

        if ($topology === Topology::Parallel) {
            $this->parallel->ensureAgentsAreContainerResolvable($swarm->agents(), $swarm::class);
        }

        if ($topology === Topology::Hierarchical) {
            $this->hierarchical->ensureUniqueWorkerClassesForSwarm($swarm);
        }
    }

    protected function ensureStreamableTopology(Swarm $swarm): void
    {
        $topology = $this->resolver->resolveTopology($swarm);

        if ($topology !== Topology::Sequential) {
            throw new SwarmException("Streaming is only supported for sequential swarms. {$topology->value} topology does not support streaming.");
        }
    }

    protected function ensureDatabaseDurableInfrastructure(): void
    {
        if (! $this->contextStore instanceof DatabaseContextStore
            || ! $this->artifactRepository instanceof DatabaseArtifactRepository
            || ! $this->historyStore instanceof DatabaseRunHistoryStore
            || ! $this->durableRuns instanceof DatabaseDurableRunStore) {
            throw new SwarmException('Durable execution requires database-backed swarm persistence and the durable runtime table.');
        }

        $this->contextStore->assertReady();
        $this->artifactRepository->assertReady();
        $this->historyStore->assertReady();
        $this->durableRuns->assertReady();
    }

    protected function ensureQueueable(Swarm $swarm): void
    {
        $swarmClass = $swarm::class;
        $constructor = new ReflectionClass($swarmClass)->getConstructor();

        if ($constructor === null) {
            return;
        }

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                continue;
            }

            if ($this->isQueueSafeDependency($parameter)) {
                continue;
            }

            $parameterType = $parameter->getType();
            $parameterName = $parameter->getName();
            $typeName = match (true) {
                $parameterType instanceof ReflectionNamedType => $parameterType->getName(),
                $parameterType instanceof ReflectionUnionType => implode('|', array_map(
                    fn (ReflectionType $type): string => $this->reflectionTypeName($type),
                    $parameterType->getTypes(),
                )),
                $parameterType instanceof ReflectionIntersectionType => implode('&', array_map(
                    fn (ReflectionType $type): string => $this->reflectionTypeName($type),
                    $parameterType->getTypes(),
                )),
                default => 'untyped',
            };

            throw new NonQueueableSwarmException(
                "Queued swarms must be container-resolvable workflow definitions. [{$swarmClass}] ".
                "cannot be queued because constructor parameter [\${$parameterName}] uses [{$typeName}] instead of a container dependency. ".
                'Do not put per-execution state in the swarm constructor; pass it in the task or RunContext instead.',
            );
        }
    }

    protected function isQueueSafeDependency(ReflectionParameter $parameter): bool
    {
        $parameterType = $parameter->getType();

        if (! $parameterType instanceof ReflectionNamedType) {
            return false;
        }

        if ($parameterType->isBuiltin()) {
            return false;
        }

        return class_exists($parameterType->getName()) || interface_exists($parameterType->getName());
    }

    protected function reflectionTypeName(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        return (string) $type;
    }

    protected function ensureContainerResolvable(Swarm $swarm): void
    {
        $swarmClass = $swarm::class;

        try {
            Container::getInstance()->make($swarmClass);
        } catch (BindingResolutionException $exception) {
            throw new NonQueueableSwarmException(
                "Queued swarms must be container-resolvable workflow definitions. [{$swarmClass}] ".
                'could not be resolved from the container for queued execution. '.
                "Underlying container error: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }
}
