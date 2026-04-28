<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Attributes\DurableParallelFailurePolicy as DurableParallelFailurePolicyAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps as MaxAgentStepsAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Timeout as TimeoutAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Topology as TopologyAttribute;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ReflectionClass;
use ValueError;

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
            throw new SwarmException("Invalid swarm topology [{$configured}]. Supported topologies: sequential, parallel, hierarchical.", previous: $exception);
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
}
