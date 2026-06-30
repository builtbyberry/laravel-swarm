<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
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
    /**
     * Durable topologies whose per-node streaming is actually wired (#310 → #311/#312).
     * This list is the single source of truth for "what `#[DurableStreaming]` streams":
     * a swarm that opts in on any topology NOT in this set fails loud at dispatch
     * rather than silently pinning the opt-in and never streaming. A topology is added
     * here only in the same change that wires its streaming and proves it with a
     * positive test — so a missed topology is a loud dispatch error, never a silent
     * no-op. Grows to the full topology set as #311 (hierarchical) and #312 (parallel)
     * land.
     *
     * @var list<Topology>
     */
    private const STREAMING_SUPPORTED_TOPOLOGIES = [Topology::Sequential];

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
        protected CausalLogStore $causalLog,
        protected ConfigRepository $config,
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

    public function ensureDatabaseDurableInfrastructure(Swarm $swarm): void
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

        $this->ensureDurableStreamingInfrastructure(
            $this->resolver->resolveDurableStreaming($swarm),
            $this->resolver->resolveTopology($swarm),
        );
    }

    /**
     * Gate the per-swarm durable per-node streaming opt-in (#298/#310): when the
     * swarm carries `#[DurableStreaming]`, the database causal log the advancer
     * appends node events to — and retracts a crashed attempt against on resume —
     * must be available and migrated. Fail loud at dispatch rather than silently
     * dropping events or falling back to prompt(). Swarms without the attribute pass
     * `false` and never reach this check.
     *
     * This runs only after {@see ensureDatabaseDurableInfrastructure()} has already
     * verified the database durable stores, so the persistence driver is database
     * by here; the remaining check is that the causal log (always the database
     * store) carries the void-edge and durable-streaming columns.
     *
     * Topology guard (#310): `#[DurableStreaming]` is a topology-agnostic surface — an
     * author can declare it on any swarm — but only the topologies in
     * {@see self::STREAMING_SUPPORTED_TOPOLOGIES} actually stream. Opting in on an
     * unsupported topology fails loud here rather than silently pinning the opt-in and
     * never streaming. #311/#312 add the remaining topologies to the allow-list.
     */
    public function ensureDurableStreamingInfrastructure(bool $durableStreaming, Topology $topology): void
    {
        if (! $durableStreaming) {
            return;
        }

        if (! in_array($topology, self::STREAMING_SUPPORTED_TOPOLOGIES, true)) {
            throw new SwarmException("Durable per-node streaming (#[DurableStreaming]) is currently supported only for sequential durable swarms; {$topology->value} streaming arrives in a later release. Remove #[DurableStreaming] from the swarm or use a sequential topology.");
        }

        if (! $this->causalLog instanceof DatabaseCausalLogStore) {
            throw new SwarmException('Durable per-node streaming (#[DurableStreaming]) requires the database persistence driver so the causal log is available to append node events to. Remove #[DurableStreaming] from the swarm or switch [swarm.persistence.driver] to database.');
        }

        $this->causalLog->assertReady();
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
                $this->ensureDatabaseDurableInfrastructure($swarm);
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
