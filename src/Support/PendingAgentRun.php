<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;

/**
 * Fluent entry point for running a single agent through the full governed
 * Swarm pipeline without authoring a {@see Swarm}
 * class.
 *
 * Returned by {@see SwarmRunner::agent()}
 * (reachable as `Swarm::agent($agent)`):
 *
 *   Swarm::agent($agent)->prompt($task);
 *   Swarm::agent($agent)->guardrails([BudgetGuardrail::class])->stream($task);
 *
 * The execution modes and additive `guardrails()` live on {@see PendingRun};
 * this class only supplies the one-element swarm.
 */
class PendingAgentRun extends PendingRun
{
    public function __construct(protected Agent $agent) {}

    /**
     * Materialize the one-element swarm carrying the collected guardrails.
     */
    protected function toSwarm(): AdHocSwarm
    {
        return new AdHocSwarm([$this->agent], $this->guardrails);
    }
}
