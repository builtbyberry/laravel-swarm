<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps as MaxAgentStepsAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Timeout as TimeoutAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Topology as TopologyAttribute;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\ExecutionPolicyResolver;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Generator;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use ReflectionClass;

class SwarmRunner
{
    public function __construct(
        protected ConfigRepository $config,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected RunHistoryStore $historyStore,
        protected ExecutionPolicyResolver $executionPolicyResolver,
        protected BusDispatcher $bus,
        protected Dispatcher $events,
        protected SequentialRunner $sequential,
        protected ParallelRunner $parallel,
        protected HierarchicalRunner $hierarchical,
    ) {}

    public function run(Swarm $swarm, string|array|RunContext $task): SwarmResponse
    {
        $topology = $this->resolveTopology($swarm);
        $executionMode = $this->resolveExecutionMode($swarm);
        $timeoutSeconds = $this->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolveMaxAgentExecutions($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $context = RunContext::from($task);
        $context->mergeMetadata([
            'swarm_class' => $swarm::class,
            'topology' => $topology->value,
            'execution_mode' => $executionMode->value,
        ]);

        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: $topology->value,
            executionMode: $executionMode,
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
            context: $context,
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
            ));

            throw $exception;
        }

        $this->contextStore->put($context, $contextTtl);
        $this->historyStore->complete($context->runId, $response, $contextTtl);
        $this->events->dispatch(new SwarmCompleted(
            runId: $context->runId,
            swarmClass: $swarm::class,
            response: $response,
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

        $executionMode = $this->resolveExecutionMode($swarm);
        $timeoutSeconds = $this->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolveMaxAgentExecutions($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $context = RunContext::from($task);
        $context->mergeMetadata([
            'swarm_class' => $swarm::class,
            'topology' => $topology->value,
            'execution_mode' => $executionMode->value,
        ]);

        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: $topology->value,
            executionMode: $executionMode,
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
            context: $context,
        ));

        return (function () use ($state, $context, $contextTtl, $swarm): Generator {
            try {
                yield from $this->sequential->stream($state);

                $response = new SwarmResponse(
                    output: (string) ($context->data['last_output'] ?? $context->input),
                    context: $context,
                    artifacts: $context->artifacts,
                    metadata: [
                        'run_id' => $context->runId,
                        'topology' => $state->topology,
                        'execution_mode' => $state->executionMode->value,
                    ],
                );

                $this->contextStore->put($context, $contextTtl);
                $this->historyStore->complete($context->runId, $response, $contextTtl);
                $this->events->dispatch(new SwarmCompleted(
                    runId: $context->runId,
                    swarmClass: $swarm::class,
                    response: $response,
                ));
            } catch (\Throwable $exception) {
                $this->historyStore->fail($context->runId, $exception, $contextTtl);
                $this->events->dispatch(new SwarmFailed(
                    runId: $context->runId,
                    swarmClass: $swarm::class,
                    exception: $exception,
                ));

                throw $exception;
            }
        })();
    }

    public function queue(Swarm $swarm, string|array|RunContext $task): QueuedSwarmResponse
    {
        $context = RunContext::from($task);
        $job = new InvokeSwarm($swarm, $context);

        if ($connection = $this->config->get('swarm.queue.connection')) {
            $job->onConnection($connection);
        }

        if ($name = $this->config->get('swarm.queue.name')) {
            $job->onQueue($name);
        }

        $this->bus->dispatch($job);

        return new QueuedSwarmResponse($job, $context->runId);
    }

    public function dispatch(Swarm $swarm, string|array|RunContext $task): SwarmResponse|QueuedSwarmResponse
    {
        return match ($this->resolveExecutionMode($swarm)) {
            ExecutionMode::Sync => $this->run($swarm, $task),
            ExecutionMode::Queued => $this->queue($swarm, $task),
            ExecutionMode::Mixed => $this->dispatchMixed($swarm, $task),
        };
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

    public function resolveExecutionMode(Swarm $swarm): ExecutionMode
    {
        return $this->executionPolicyResolver->resolve($swarm);
    }

    protected function dispatchMixed(Swarm $swarm, string|array|RunContext $task): SwarmResponse|QueuedSwarmResponse
    {
        $queuedTopologies = (array) $this->config->get('swarm.execution.mixed_queue_topologies', [Topology::Parallel->value]);

        if (in_array($this->resolveTopology($swarm)->value, $queuedTopologies, true)) {
            return $this->queue($swarm, $task);
        }

        return $this->run($swarm, $task);
    }
}
