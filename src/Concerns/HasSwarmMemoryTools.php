<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Concerns;

use BuiltByBerry\LaravelSwarm\Tools\Recall;
use BuiltByBerry\LaravelSwarm\Tools\Remember;
use Illuminate\Container\Container;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;

/**
 * Opt-in trait that adds the {@see Recall} and {@see Remember} memory tools to a
 * `laravel/ai` agent.
 *
 * Merge {@see swarmMemoryTools()} into the agent's own `tools()`:
 *
 * ```php
 * use Laravel\Ai\Contracts\HasTools;
 * use BuiltByBerry\LaravelSwarm\Concerns\HasSwarmMemoryTools;
 *
 * class Researcher implements Agent, HasTools
 * {
 *     use HasSwarmMemoryTools;
 *
 *     public function tools(): iterable
 *     {
 *         return [...$this->swarmMemoryTools(), new MyOtherTool];
 *     }
 * }
 * ```
 *
 * Whether the tools are actually returned is governed by `config('swarm.memory
 * .tools')`: `enabled` is the master switch (default off, so adding the trait is
 * safe and inert until you opt in app-wide), and `recall` / `remember` toggle
 * each tool. The concrete classes are resolved from the container so an
 * application can bind a subclass without changing agent code. When the config
 * is unavailable (an unbooted container) the trait returns no tools rather than
 * guessing.
 *
 * The Agent memory scope stays unaddressable unless two things are both set:
 * the agent asks for it with `swarmMemoryTools(agentScope: true)`, which binds
 * each resolved tool to the agent with `forAgent()`, and
 * `swarm.memory.tools.agent_scope` is true (see {@see Recall::forAgent()} and
 * {@see Remember::forAgent()}). That scope is keyed by agent class, so it is
 * shared across every run and tenant of the class; which agents are shown its
 * entries is a separate decision, made by the swarm's propagation policy.
 */
trait HasSwarmMemoryTools
{
    /**
     * The Swarm memory tools enabled for this agent, per `swarm.memory.tools`.
     * Pass `agentScope: true` to bind them to this agent; the binding takes
     * effect while `swarm.memory.tools.agent_scope` is on.
     *
     * @return list<Tool>
     */
    public function swarmMemoryTools(bool $agentScope = false): array
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return [];
        }

        $config = $container->make('config');

        if (! (bool) $config->get('swarm.memory.tools.enabled', false)) {
            return [];
        }

        $bind = $agentScope && $this instanceof Agent;

        $tools = [];

        if ((bool) $config->get('swarm.memory.tools.recall', true)) {
            $recall = $container->make(Recall::class);
            $tools[] = $bind ? $recall->forAgent($this) : $recall;
        }

        if ((bool) $config->get('swarm.memory.tools.remember', true)) {
            $remember = $container->make(Remember::class);
            $tools[] = $bind ? $remember->forAgent($this) : $remember;
        }

        return $tools;
    }
}
