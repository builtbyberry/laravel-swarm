<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;

/**
 * Fluent entry point for running a multi-agent swarm inline, without authoring
 * a {@see \BuiltByBerry\LaravelSwarm\Contracts\Swarm} class.
 *
 * Returned by {@see \BuiltByBerry\LaravelSwarm\Runners\SwarmRunner}'s
 * `sequential()`, `parallel()` and `hierarchical()` entry points (reachable as
 * `Swarm::sequential()` etc.):
 *
 *   Swarm::sequential([$research, $write])->prompt($task);
 *   Swarm::parallel([$a, $b, $c])->guardrails([BudgetGuardrail::class])->prompt($task);
 *   Swarm::hierarchical($coordinator, [$writer, $editor])->stream($task);
 *
 * The execution modes and additive `guardrails()` live on {@see PendingRun};
 * this class supplies the topology-pinned {@see AdHocSwarm} subclass and its
 * agents.
 */
class PendingSwarmRun extends PendingRun
{
    /**
     * @param  class-string<AdHocSwarm>  $swarmClass  A topology-pinned AdHocSwarm subclass.
     * @param  array<int, Agent>  $agents  For hierarchical, agents[0] is the coordinator.
     */
    public function __construct(
        protected string $swarmClass,
        protected array $agents,
    ) {}

    /**
     * Materialize the topology-pinned swarm carrying the collected guardrails.
     */
    protected function toSwarm(): AdHocSwarm
    {
        return new $this->swarmClass($this->agents, $this->guardrails);
    }
}
