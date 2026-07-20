<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Swarms\ParallelResearchFanout;

use {{ rootNamespace }}\Ai\Agents\ParallelResearchFanout\CompetitorScout;
use {{ rootNamespace }}\Ai\Agents\ParallelResearchFanout\CustomerScout;
use {{ rootNamespace }}\Ai\Agents\ParallelResearchFanout\MarketScout;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

/**
 * parallel-research-fanout — dispatch one prompt to multiple agents at once.
 *
 * Topology: Parallel. Every agent receives the original task. Laravel
 * Concurrency runs them in worker callbacks, then the runner merges the
 * results back into a single SwarmResponse. The `$response->steps` collection
 * holds one step per agent in declaration order.
 *
 * Demonstrates: Parallel topology, fan-out / join, container-resolvable
 * stateless agents, ordered step results from concurrent execution.
 *
 * Container contract: parallel agents must be resolvable by class name.
 * The three scouts here are plain concrete classes, so Laravel's container
 * can construct them with no bindings. If an agent depends on an interface,
 * bind it in a service provider.
 *
 * Next step: docs/parallel.md
 */
#[Topology(TopologyEnum::Parallel)]
class ResearchFanout implements Swarm
{
    use Runnable;

    /**
     * @return array<int, \Laravel\Ai\Contracts\Agent>
     */
    public function agents(): array
    {
        return [
            new MarketScout,
            new CompetitorScout,
            new CustomerScout,
        ];
    }
}
