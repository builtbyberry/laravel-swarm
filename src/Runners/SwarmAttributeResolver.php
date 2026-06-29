<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Attributes\ContextGrowthPolicy as ContextGrowthPolicyAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\DurableParallelFailurePolicy as DurableParallelFailurePolicyAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps as MaxAgentStepsAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\PropagationPolicy as PropagationPolicyAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\QueuedHierarchicalParallelCoordination as QueuedHierarchicalParallelCoordinationAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\StreamParallelBranches as StreamParallelBranchesAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Timeout as TimeoutAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Topology as TopologyAttribute;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\GrowthPolicy;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\AgentVisibleMemoryView;
use BuiltByBerry\LaravelSwarm\Memory\DefaultPropagationPolicy;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ReflectionClass;
use ValueError;

/**
 * @internal
 */
class SwarmAttributeResolver
{
    public function __construct(
        protected ConfigRepository $config,
    ) {}

    public function resolveTopology(Swarm $swarm): Topology
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(TopologyAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->topology;
        }

        $configured = (string) $this->config->get('swarm.topology', Topology::Sequential->value);

        try {
            return Topology::from($configured);
        } catch (ValueError $exception) {
            throw new SwarmException("Invalid swarm topology [{$configured}]. Supported topologies: sequential, parallel, hierarchical, static_hierarchical.", previous: $exception);
        }
    }

    public function resolveTimeoutSeconds(Swarm $swarm): int
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(TimeoutAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->seconds;
        }

        $seconds = (int) $this->config->get('swarm.timeout', 300);

        if ($seconds <= 0) {
            throw new SwarmException('Swarm timeout must be a positive integer.');
        }

        return $seconds;
    }

    public function resolveMaxAgentExecutions(Swarm $swarm): int
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(MaxAgentStepsAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->steps;
        }

        $steps = (int) $this->config->get('swarm.max_agent_steps', 10);

        if ($steps <= 0) {
            throw new SwarmException('Swarm max agent steps must be a positive integer.');
        }

        return $steps;
    }

    /**
     * @return 'in_process'|'multi_worker'
     */
    public function resolveQueueHierarchicalParallelCoordination(Swarm $swarm): string
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(QueuedHierarchicalParallelCoordinationAttribute::class);

        if ($attributes !== []) {
            $value = $attributes[0]->newInstance()->coordination;

            if (! in_array($value, ['in_process', 'multi_worker'], true)) {
                throw new SwarmException("Invalid queued hierarchical parallel coordination [{$value}]. Supported values: in_process, multi_worker.");
            }

            return $value;
        }

        $configured = (string) $this->config->get('swarm.queue.hierarchical_parallel.coordination', 'in_process');

        if (! in_array($configured, ['in_process', 'multi_worker'], true)) {
            throw new SwarmException("Invalid swarm.queue.hierarchical_parallel.coordination [{$configured}]. Supported values: in_process, multi_worker.");
        }

        return $configured;
    }

    /**
     * @return 'concurrent'|'sequential'
     */
    public function resolveStreamParallelBranches(Swarm $swarm): string
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(StreamParallelBranchesAttribute::class);

        if ($attributes !== []) {
            $value = $attributes[0]->newInstance()->mode;

            if (! in_array($value, ['concurrent', 'sequential'], true)) {
                throw new SwarmException("Invalid stream parallel branches mode [{$value}]. Supported values: concurrent, sequential.");
            }

            return $value;
        }

        $configured = (string) $this->config->get('swarm.static_hierarchical.stream_parallel_branches', 'concurrent');

        if (! in_array($configured, ['concurrent', 'sequential'], true)) {
            throw new SwarmException("Invalid swarm.static_hierarchical.stream_parallel_branches [{$configured}]. Supported values: concurrent, sequential.");
        }

        return $configured;
    }

    /**
     * @return 'concurrent'|'sequential'
     */
    public function resolveStreamParallelBranchesForHierarchical(Swarm $swarm): string
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(StreamParallelBranchesAttribute::class);

        if ($attributes !== []) {
            $value = $attributes[0]->newInstance()->mode;

            if (! in_array($value, ['concurrent', 'sequential'], true)) {
                throw new SwarmException("Invalid stream parallel branches mode [{$value}]. Supported values: concurrent, sequential.");
            }

            return $value;
        }

        $configured = (string) $this->config->get('swarm.hierarchical.stream_parallel_branches', 'concurrent');

        if (! in_array($configured, ['concurrent', 'sequential'], true)) {
            throw new SwarmException("Invalid swarm.hierarchical.stream_parallel_branches [{$configured}]. Supported values: concurrent, sequential.");
        }

        return $configured;
    }

    public function resolveDurableParallelFailurePolicy(Swarm $swarm): DurableParallelFailurePolicy
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(DurableParallelFailurePolicyAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->policy;
        }

        $configured = (string) $this->config->get('swarm.durable.parallel.failure_policy', DurableParallelFailurePolicy::CollectFailures->value);

        try {
            return DurableParallelFailurePolicy::from($configured);
        } catch (ValueError $exception) {
            throw new SwarmException("Invalid durable parallel failure policy [{$configured}]. Supported policies: collect_failures, fail_run, partial_success.", previous: $exception);
        }
    }

    /**
     * Resolve the context-growth policy for a swarm: the
     * `#[ContextGrowthPolicy(...)]` attribute when present, otherwise the
     * configured `swarm.context_growth.policy` default (warn + degrade-to-cold).
     *
     * Parsing is lenient and fail-safe: an unrecognised configured value falls
     * back to the framework default rather than throwing, so a typo in operator
     * config can never wedge a run — consistent with the policy's own fail-safe
     * contract (#288).
     */
    public function resolveGrowthPolicy(Swarm $swarm): GrowthPolicy
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(ContextGrowthPolicyAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->policy;
        }

        $configured = $this->config->get('swarm.context_growth.policy');

        return GrowthPolicy::tryFromConfig(is_string($configured) ? $configured : null);
    }

    /**
     * Resolve the propagation-policy class for a swarm: the
     * `#[PropagationPolicy(...)]` attribute when present, otherwise the
     * configured `swarm.memory.propagation_policy` default.
     *
     * Returns the class-string rather than an instance so this resolver stays
     * container-free, consistent with its other resolvers. The caller
     * ({@see AgentVisibleMemoryView}) resolves
     * and type-guards it.
     *
     * @return class-string<MemoryPropagationPolicy>
     */
    public function resolvePropagationPolicyClass(Swarm $swarm): string
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(PropagationPolicyAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->policy;
        }

        /** @var class-string<MemoryPropagationPolicy> $configured */
        $configured = (string) $this->config->get('swarm.memory.propagation_policy', DefaultPropagationPolicy::class);

        return $configured;
    }
}
