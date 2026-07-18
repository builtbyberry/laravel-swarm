<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Facades;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\PendingAgentRun;
use BuiltByBerry\LaravelSwarm\Support\PendingSwarmRun;
use Illuminate\Support\Facades\Facade;

/**
 * The public entry point for running swarms.
 *
 * The fluent, class-free builders below are surfaced explicitly so IDEs and
 * static analysis autocomplete them straight off the facade (the backing
 * {@see SwarmRunner} is `@internal`). Each returns a fluent pending run whose
 * execution modes — `prompt()`, `run()`, `stream()`, `queue()`, `broadcast()`,
 * `broadcastNow()`, `broadcastOnQueue()`, `dispatchDurable()` — and additive
 * `guardrails()` live on {@see \BuiltByBerry\LaravelSwarm\Support\PendingRun}:
 *
 *   Swarm::agent($agent)->prompt($task);
 *   Swarm::agent($agent)->guardrails([BudgetGuardrail::class])->stream($task);
 *   Swarm::sequential([$research, $write])->prompt($task);
 *   Swarm::parallel([$a, $b, $c])->guardrails([BudgetGuardrail::class])->prompt($task);
 *   Swarm::hierarchical($coordinator, [$writer, $editor])->stream($task);
 *
 * The remaining runtime surface (running hand-authored swarm classes, `memory()`,
 * etc.) is inherited from the `@mixin` below.
 *
 * @method static PendingAgentRun agent(Agent $agent) Begin a governed run for a single agent without authoring a Swarm class.
 * @method static PendingSwarmRun sequential(array<int, Agent> $agents) Begin a governed sequential run for the given agents; each agent's output feeds the next.
 * @method static PendingSwarmRun parallel(array<int, Agent> $agents) Begin a governed parallel run for the given agents; every agent runs against the same task.
 * @method static PendingSwarmRun hierarchical(Agent $coordinator, array<int, Agent> $workers = []) Begin a governed hierarchical run; the coordinator routes over the given worker agents.
 *
 * @mixin SwarmRunner
 */
class Swarm extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return class-string<SwarmRunner>
     */
    protected static function getFacadeAccessor(): string
    {
        return SwarmRunner::class;
    }
}
