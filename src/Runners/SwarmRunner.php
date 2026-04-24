<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps as MaxAgentStepsAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Timeout as TimeoutAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Topology as TopologyAttribute;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ClaimsQueuedRunExecution;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Bus\PendingDispatch;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

class SwarmRunner
{
    protected const EXECUTION_MODE_RUN = 'run';

    protected const EXECUTION_MODE_STREAM = 'stream';

    protected const EXECUTION_MODE_QUEUE = 'queue';

    protected const EXECUTION_MODE_DURABLE = 'durable';

    public function __construct(
        protected ConfigRepository $config,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected RunHistoryStore $historyStore,
        protected DurableRunStore $durableRuns,
        protected Dispatcher $events,
        protected SequentialRunner $sequential,
        protected ParallelRunner $parallel,
        protected HierarchicalRunner $hierarchical,
        protected DurableSwarmManager $durable,
    ) {}

    public function run(Swarm $swarm, string|array|RunContext $task): SwarmResponse
    {
        return $this->runWithExecutionMode($swarm, $task, self::EXECUTION_MODE_RUN);
    }

    public function runQueued(Swarm $swarm, string|array|RunContext $task): ?SwarmResponse
    {
        return $this->runWithExecutionMode($swarm, $task, self::EXECUTION_MODE_QUEUE);
    }

    protected function runWithExecutionMode(Swarm $swarm, string|array|RunContext $task, string $executionMode): ?SwarmResponse
    {
        $startedAt = MonotonicTime::now();
        $topology = $this->resolveTopology($swarm);
        $timeoutSeconds = $this->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolveMaxAgentExecutions($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $queueLeaseSeconds = $executionMode === self::EXECUTION_MODE_QUEUE
            ? $this->resolveQueueLeaseSeconds($timeoutSeconds)
            : null;
        $context = RunContext::fromTask($task);
        $context->mergeMetadata([
            'swarm_class' => $swarm::class,
            'topology' => $topology->value,
        ]);

        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: $topology->value,
            deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: $maxAgentExecutions,
            ttlSeconds: $contextTtl,
            leaseSeconds: $queueLeaseSeconds,
            executionToken: null,
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
        );

        if ($executionMode === self::EXECUTION_MODE_QUEUE && $this->historyStore instanceof ClaimsQueuedRunExecution) {
            $acquisition = $this->historyStore->acquireQueuedRun(
                $context->runId,
                $swarm::class,
                $topology->value,
                $context,
                $context->metadata,
                $contextTtl,
                $queueLeaseSeconds ?? $timeoutSeconds,
            );

            if (! $acquisition->acquired()) {
                return null;
            }

            $state = new SwarmExecutionState(
                swarm: $swarm,
                topology: $topology->value,
                deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
                maxAgentExecutions: $maxAgentExecutions,
                ttlSeconds: $contextTtl,
                leaseSeconds: $queueLeaseSeconds,
                executionToken: $acquisition->executionToken,
                context: $context,
                contextStore: $this->contextStore,
                artifactRepository: $this->artifactRepository,
                historyStore: $this->historyStore,
                events: $this->events,
            );
        } else {
            $this->historyStore->start($context->runId, $swarm::class, $topology->value, $context, $context->metadata, $contextTtl);
        }

        $this->contextStore->put($context, $contextTtl);
        $this->events->dispatch(new SwarmStarted(
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $topology->value,
            input: $context->input,
            metadata: $context->metadata,
            executionMode: $executionMode,
        ));

        try {
            $response = match ($topology) {
                Topology::Sequential => $this->sequential->run($state),
                Topology::Parallel => $this->parallel->run($state),
                Topology::Hierarchical => $this->hierarchical->run($state),
            };
        } catch (LostSwarmLeaseException) {
            return null;
        } catch (\Throwable $exception) {
            try {
                $this->historyStore->fail($context->runId, $exception, $contextTtl, $state->executionToken, $state->leaseSeconds);
            } catch (LostSwarmLeaseException) {
                return null;
            }

            $this->events->dispatch(new SwarmFailed(
                runId: $context->runId,
                swarmClass: $swarm::class,
                topology: $topology->value,
                exception: $exception,
                durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
                metadata: $context->metadata,
                executionMode: $executionMode,
            ));

            throw $exception;
        }

        $response = $this->normalizeCompletionResponse($response, $context, $topology->value);
        $this->contextStore->put($context, $contextTtl);
        $this->historyStore->complete($context->runId, $response, $contextTtl, $state->executionToken, $state->leaseSeconds);
        $this->events->dispatch(new SwarmCompleted(
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $topology->value,
            output: $response->output,
            durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
            metadata: $response->metadata,
            artifacts: $response->artifacts,
            executionMode: $executionMode,
        ));

        return $response;
    }

    /**
     * @return Generator<int, array<string, string>, mixed, void>
     */
    public function stream(Swarm $swarm, string|array|RunContext $task): Generator
    {
        $topology = $this->resolveTopology($swarm);

        if ($topology !== Topology::Sequential) {
            throw new SwarmException('Streaming is only supported for sequential swarms. '.$topology->value.' topology does not support streaming.');
        }

        $timeoutSeconds = $this->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolveMaxAgentExecutions($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $context = RunContext::fromTask($task);
        $context->mergeMetadata([
            'swarm_class' => $swarm::class,
            'topology' => $topology->value,
        ]);

        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: $topology->value,
            deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: $maxAgentExecutions,
            ttlSeconds: $contextTtl,
            leaseSeconds: null,
            executionToken: null,
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
        );

        $this->contextStore->put($context, $contextTtl);
        $this->historyStore->start($context->runId, $swarm::class, $topology->value, $context, $context->metadata, $contextTtl);
        $this->events->dispatch(new SwarmStarted(
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $topology->value,
            input: $context->input,
            metadata: $context->metadata,
            executionMode: self::EXECUTION_MODE_STREAM,
        ));

        return (function () use ($state, $context, $contextTtl, $swarm): Generator {
            $startedAt = MonotonicTime::now();

            try {
                yield from $this->sequential->stream($state);

                $response = $this->normalizeCompletionResponse(new SwarmResponse(
                    output: (string) ($context->data['last_output'] ?? $context->input),
                    context: $context,
                    artifacts: $context->artifacts,
                    usage: is_array($context->metadata['usage'] ?? null) ? $context->metadata['usage'] : [],
                ), $context, $state->topology);

                $this->contextStore->put($context, $contextTtl);
                $this->historyStore->complete($context->runId, $response, $contextTtl);
                $this->events->dispatch(new SwarmCompleted(
                    runId: $context->runId,
                    swarmClass: $swarm::class,
                    topology: $state->topology,
                    output: $response->output,
                    durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
                    metadata: $response->metadata,
                    artifacts: $response->artifacts,
                    executionMode: self::EXECUTION_MODE_STREAM,
                ));
            } catch (\Throwable $exception) {
                $this->historyStore->fail($context->runId, $exception, $contextTtl);
                $this->events->dispatch(new SwarmFailed(
                    runId: $context->runId,
                    swarmClass: $swarm::class,
                    topology: $state->topology,
                    exception: $exception,
                    durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
                    metadata: $context->metadata,
                    executionMode: self::EXECUTION_MODE_STREAM,
                ));

                throw $exception;
            }
        })();
    }

    public function queue(Swarm $swarm, string|array|RunContext $task): QueuedSwarmResponse
    {
        $this->ensureQueueable($swarm);
        $this->ensureContainerResolvable($swarm);

        $context = RunContext::fromTask($task);
        $pendingDispatch = InvokeSwarm::dispatch($swarm::class, $context->toQueuePayload());

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
        $this->ensureDurableSupported($swarm);
        $this->ensureQueueable($swarm);
        $this->ensureContainerResolvable($swarm);
        $this->ensureDatabaseDurableInfrastructure();

        $topology = $this->resolveTopology($swarm);
        $timeoutSeconds = $this->resolveTimeoutSeconds($swarm);
        $totalSteps = min(count($swarm->agents()), $this->resolveMaxAgentExecutions($swarm));
        $context = RunContext::fromTask($task);
        $start = $this->durable->start($swarm, $context, $topology, $timeoutSeconds, $totalSteps);

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

    protected function ensureDurableSupported(Swarm $swarm): void
    {
        $topology = $this->resolveTopology($swarm);

        if ($topology !== Topology::Sequential) {
            throw new SwarmException('Durable execution is only supported for sequential swarms in this release.');
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
    }

    protected function databaseHistoryStore(): DatabaseRunHistoryStore
    {
        if (! $this->historyStore instanceof DatabaseRunHistoryStore) {
            throw new SwarmException('Durable execution requires the database run history store.');
        }

        return $this->historyStore;
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
                    static fn (ReflectionNamedType $type): string => $type->getName(),
                    $parameterType->getTypes(),
                )),
                $parameterType instanceof ReflectionIntersectionType => implode('&', array_map(
                    static fn (ReflectionNamedType $type): string => $type->getName(),
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

    public function resolveTopology(Swarm $swarm): Topology
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(TopologyAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->topology;
        }

        return Topology::from((string) $this->config->get('swarm.topology', Topology::Sequential->value));
    }

    public function resolveTimeoutSeconds(Swarm $swarm): int
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(TimeoutAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->seconds;
        }

        return (int) $this->config->get('swarm.timeout', 300);
    }

    public function resolveMaxAgentExecutions(Swarm $swarm): int
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(MaxAgentStepsAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->steps;
        }

        return (int) $this->config->get('swarm.max_agent_steps', 10);
    }
}
