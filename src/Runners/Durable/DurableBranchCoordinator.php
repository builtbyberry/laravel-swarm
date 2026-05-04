<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableBranches;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;

class DurableBranchCoordinator
{
    public function __construct(
        protected ConfigRepository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $branch
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    public function withBranchRouting(Swarm $swarm, RunContext $context, array $branch, array $run): array
    {
        if (($run['coordination_profile'] ?? CoordinationProfile::StepDurable->value) === CoordinationProfile::QueueHierarchicalParallel->value) {
            $connection = $this->config->get('swarm.queue.hierarchical_parallel.branch.connection')
                ?? $this->config->get('swarm.queue.hierarchical_parallel.connection')
                ?? $this->config->get('swarm.queue.connection');
            $queue = $this->config->get('swarm.queue.hierarchical_parallel.branch.name')
                ?? $this->config->get('swarm.queue.hierarchical_parallel.name')
                ?? $this->config->get('swarm.queue.name');
        } else {
            $connection = $this->config->get('swarm.durable.parallel.queue.connection');
            $queue = $this->config->get('swarm.durable.parallel.queue.name');

            if ($connection === null) {
                $connection = $run['queue_connection'];
            }

            if ($queue === null) {
                $queue = $run['queue_name'];
            }
        }

        if ($swarm instanceof RoutesDurableBranches) {
            $routing = $swarm->durableBranchQueue($context, $branch);
            $connection = array_key_exists('connection', $routing) ? $routing['connection'] : $connection;
            $queue = array_key_exists('queue', $routing) ? $routing['queue'] : $queue;
        }

        $branch['queue_connection'] = $connection;
        $branch['queue_name'] = $queue;

        return $branch;
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     */
    public function branchesAreTerminal(array $branches): bool
    {
        if ($branches === []) {
            return false;
        }

        foreach ($branches as $branch) {
            if (! in_array($branch['status'] ?? null, ['completed', 'failed', 'cancelled'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $branch
     */
    public function branchShouldBeRedispatched(array $branch): bool
    {
        if ($branch['status'] === 'pending') {
            return true;
        }

        if ($branch['status'] !== 'running') {
            return false;
        }

        if (($branch['leased_until'] ?? null) === null) {
            return true;
        }

        return Carbon::parse((string) $branch['leased_until'], 'UTC')->isPast();
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<string, int>
     */
    public function mergeBranchUsage(array $branches): array
    {
        $usage = [];

        foreach ($branches as $branch) {
            foreach ((array) ($branch['usage'] ?? []) as $key => $value) {
                if (is_int($value)) {
                    $usage[$key] = ($usage[$key] ?? 0) + $value;
                }
            }
        }

        return $usage;
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<int, array<string, mixed>>
     */
    public function branchSummaries(array $branches): array
    {
        return array_map(static fn (array $branch): array => [
            'branch_id' => $branch['branch_id'],
            'node_id' => $branch['node_id'],
            'agent_class' => $branch['agent_class'],
            'status' => $branch['status'],
            'failure' => $branch['failure'],
        ], $branches);
    }

    public function parallelFailurePolicy(RunContext $context): DurableParallelFailurePolicy
    {
        $policy = $context->metadata['durable_parallel_failure_policy'] ?? DurableParallelFailurePolicy::CollectFailures->value;

        return is_string($policy)
            ? DurableParallelFailurePolicy::tryFrom($policy) ?? DurableParallelFailurePolicy::CollectFailures
            : DurableParallelFailurePolicy::CollectFailures;
    }
}
