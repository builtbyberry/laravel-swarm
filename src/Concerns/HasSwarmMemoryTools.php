<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Concerns;

use BuiltByBerry\LaravelSwarm\Tools\Recall;
use BuiltByBerry\LaravelSwarm\Tools\Remember;
use Illuminate\Container\Container;
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
 * application can bind a subclass (e.g. an agent-scoped {@see Recall}) without
 * changing agent code. When the config is unavailable (an unbooted container)
 * the trait returns no tools rather than guessing.
 */
trait HasSwarmMemoryTools
{
    /**
     * The Swarm memory tools enabled for this agent, per `swarm.memory.tools`.
     *
     * @return array<int, Tool>
     */
    public function swarmMemoryTools(): array
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return [];
        }

        $config = $container->make('config');

        if (! (bool) $config->get('swarm.memory.tools.enabled', false)) {
            return [];
        }

        $tools = [];

        if ((bool) $config->get('swarm.memory.tools.recall', true)) {
            $tools[] = $container->make(Recall::class);
        }

        if ((bool) $config->get('swarm.memory.tools.remember', true)) {
            $tools[] = $container->make(Remember::class);
        }

        return $tools;
    }
}
