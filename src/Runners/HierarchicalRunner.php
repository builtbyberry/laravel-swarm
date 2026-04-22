<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Hierarchical topology: delegates to sequential execution until coordinator routing is implemented.
 *
 * @todo Support coordinator structured routing to invoke downstream agents with custom inputs.
 */
class HierarchicalRunner
{
    public function __construct(
        protected SequentialRunner $sequential,
    ) {}

    /**
     * @param  float  $deadlineMonotonic  hrtime(true) deadline in nanoseconds
     * @return array{response: SwarmResponse, usage: array<string, int>}
     */
    public function run(
        Swarm $swarm,
        string $task,
        float $deadlineMonotonic,
        int $maxAgentExecutions,
        string $contextKey,
        CacheRepository $cache,
        int $contextTtlSeconds,
    ): array {
        return $this->sequential->run(
            $swarm,
            $task,
            $deadlineMonotonic,
            $maxAgentExecutions,
            $contextKey,
            $cache,
            $contextTtlSeconds,
        );
    }
}
