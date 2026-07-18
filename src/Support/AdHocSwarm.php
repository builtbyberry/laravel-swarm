<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\DefinesGuardrails;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

/**
 * A swarm assembled at call time rather than declared as a class.
 *
 * This backs the {@see \BuiltByBerry\LaravelSwarm\Runners\SwarmRunner::agent()}
 * convenience: a lone agent becomes a one-element swarm that flows through the
 * exact same {@see SwarmRunner} pipeline — audit, guardrails, capture,
 * telemetry, encrypt-at-rest — as any hand-authored {@see Swarm} class. A swarm
 * of one is still a swarm; there is no second, ungoverned path.
 *
 * It carries the {@see Runnable} trait so every execution mode (prompt, stream,
 * queue, broadcast, dispatchDurable) is available with no bespoke runner, and
 * implements {@see DefinesGuardrails} so per-call guardrails merge with the
 * app's globally configured ones (the "governed by default" guarantee lives in
 * {@see \BuiltByBerry\LaravelSwarm\Runners\SwarmGuardrailRunner}, which always
 * folds in `config('swarm.guardrails')` regardless of what this returns).
 *
 * Topology is left to the resolver's default (sequential), which is a
 * pass-through for a single agent.
 *
 * @internal Constructed by SwarmRunner / PendingAgentRun, not by consumers.
 */
class AdHocSwarm implements DefinesGuardrails, Swarm
{
    use Runnable;

    /**
     * @param  array<int, Agent>  $agents
     * @param  array<int, object|class-string>  $guardrails
     */
    public function __construct(
        protected array $agents,
        protected array $guardrails = [],
    ) {}

    /**
     * @return array<int, Agent>
     */
    public function agents(): array
    {
        return $this->agents;
    }

    /**
     * @return array<int, object|class-string>
     */
    public function guardrails(): array
    {
        return $this->guardrails;
    }
}
