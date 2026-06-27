<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Dispatch-time validation for queued, durable, broadcast, and streaming entry points.
 *
 * Every public entry point on the runner orchestrator passes a swarm through one
 * or more of these checks before any state is persisted or events emitted. The
 * goal is to fail fast with an explicit exception while every guarantee about
 * agents, topology, queueability, and required persistence infrastructure is
 * still verifiable by reflection or contract inspection.
 *
 * @internal
 */
class DispatchValidator
{
    public function __construct(
        protected SwarmAttributeResolver $resolver,
        protected ParallelRunner $parallel,
        protected HierarchicalRunner $hierarchical,
        protected SwarmPayloadLimits $limits,
        protected SwarmCapture $capture,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected RunHistoryStore $historyStore,
        protected DurableRunStore $durableRuns,
    ) {}

    public function ensureSwarmHasAgents(Swarm $swarm): void
    {
        if ($swarm->agents() !== []) {
            return;
        }

        throw new SwarmException(class_basename($swarm).': swarm has no agents. Add at least one agent to agents().');
    }

    public function ensureStreamableTopology(Swarm $swarm): void
    {
        $topology = $this->resolver->resolveTopology($swarm);
        $streamable = [Topology::Sequential, Topology::StaticHierarchical, Topology::Hierarchical];

        if (! in_array($topology, $streamable, true)) {
            throw new SwarmException("Streaming is only supported for sequential, static_hierarchical, and hierarchical swarms. {$topology->value} topology does not support streaming.");
        }
    }

    public function ensureActiveContextCompatible(ExecutionMode $executionMode): void
    {
        if ($this->capture->capturesActiveContext()) {
            return;
        }

        if (in_array($executionMode, [ExecutionMode::Queue, ExecutionMode::Durable], true)) {
            throw new SwarmException('Queued and durable swarms require active runtime context persistence so workers can continue or recover the run. Enable [swarm.capture.active_context] or use synchronous execution.');
        }
    }

    public function ensureDatabaseDurableInfrastructure(): void
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

    public function validateForDispatch(Swarm $swarm): void
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

            if ($this->resolver->resolveQueueHierarchicalParallelCoordination($swarm) === 'multi_worker') {
                $this->ensureDatabaseDurableInfrastructure();
            }
        }
    }

    public function ensureQueueable(Swarm $swarm): void
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

    public function ensureContainerResolvable(Swarm $swarm): void
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

    /**
     * @param  string|array<int|string, mixed>|RunContext  $task
     */
    public function checkInputPayload(string|array|RunContext $task, RunContext $context, ExecutionMode $executionMode): void
    {
        if ($task instanceof RunContext || in_array($executionMode, [ExecutionMode::Queue, ExecutionMode::Durable], true)) {
            $this->limits->checkContextInput($context);
            $this->limits->checkMetadata($context->metadata);

            return;
        }

        $this->limits->checkInput($context->input);
        $this->limits->checkMetadata($context->metadata);
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
}
