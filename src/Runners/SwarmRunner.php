<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps as MaxAgentStepsAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Timeout as TimeoutAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Topology as TopologyAttribute;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
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

    public function __construct(
        protected ConfigRepository $config,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected RunHistoryStore $historyStore,
        protected Dispatcher $events,
        protected SequentialRunner $sequential,
        protected ParallelRunner $parallel,
        protected HierarchicalRunner $hierarchical,
    ) {}

    public function run(Swarm $swarm, string|RunContext $task): SwarmResponse
    {
        return $this->runWithExecutionMode($swarm, $task, self::EXECUTION_MODE_RUN);
    }

    public function runQueued(Swarm $swarm, string|RunContext $task): SwarmResponse
    {
        return $this->runWithExecutionMode($swarm, $task, self::EXECUTION_MODE_QUEUE);
    }

    protected function runWithExecutionMode(Swarm $swarm, string|RunContext $task, string $executionMode): SwarmResponse
    {
        $topology = $this->resolveTopology($swarm);
        $timeoutSeconds = $this->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolveMaxAgentExecutions($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $context = RunContext::from($task);
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
            executionMode: $executionMode,
        ));

        try {
            $response = match ($topology) {
                Topology::Sequential => $this->sequential->run($state),
                Topology::Parallel => $this->parallel->run($state),
                Topology::Hierarchical => $this->hierarchical->run($state),
            };
        } catch (\Throwable $exception) {
            $this->historyStore->fail($context->runId, $exception, $contextTtl);
            $this->events->dispatch(new SwarmFailed(
                runId: $context->runId,
                swarmClass: $swarm::class,
                exception: $exception,
                metadata: $context->metadata,
            ));

            throw $exception;
        }

        $response = $this->normalizeCompletionResponse($response, $context, $topology->value);
        $this->contextStore->put($context, $contextTtl);
        $this->historyStore->complete($context->runId, $response, $contextTtl);
        $this->events->dispatch(new SwarmCompleted(
            runId: $context->runId,
            swarmClass: $swarm::class,
            output: $response->output,
            metadata: $response->metadata,
            artifacts: $response->artifacts,
        ));

        return $response;
    }

    /**
     * @return Generator<int, array<string, string>, mixed, void>
     */
    public function stream(Swarm $swarm, string $task): Generator
    {
        $topology = $this->resolveTopology($swarm);

        if ($topology !== Topology::Sequential) {
            throw new SwarmException('Streaming is only supported for sequential swarms. '.$topology->value.' topology does not support streaming.');
        }

        $timeoutSeconds = $this->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolveMaxAgentExecutions($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $context = RunContext::from($task);
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
                    output: $response->output,
                    metadata: $response->metadata,
                    artifacts: $response->artifacts,
                ));
            } catch (\Throwable $exception) {
                $this->historyStore->fail($context->runId, $exception, $contextTtl);
                $this->events->dispatch(new SwarmFailed(
                    runId: $context->runId,
                    swarmClass: $swarm::class,
                    exception: $exception,
                    metadata: $context->metadata,
                ));

                throw $exception;
            }
        })();
    }

    public function queue(Swarm $swarm, string $task): QueuedSwarmResponse
    {
        $this->ensureQueueable($swarm);
        $this->ensureContainerResolvable($swarm);

        $context = RunContext::from($task);
        $pendingDispatch = InvokeSwarm::dispatch($swarm::class, $context);

        if ($connection = $this->config->get('swarm.queue.connection')) {
            $pendingDispatch->onConnection($connection);
        }

        if ($name = $this->config->get('swarm.queue.name')) {
            $pendingDispatch->onQueue($name);
        }

        return new QueuedSwarmResponse($pendingDispatch, $context->runId);
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
