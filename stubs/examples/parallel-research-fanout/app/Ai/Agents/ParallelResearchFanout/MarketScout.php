<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\ParallelResearchFanout;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * One of three parallel research agents.
 *
 * Parallel agents receive the *original* task input (not the previous step's
 * output). They run concurrently via Laravel Concurrency, so they must be
 * stateless and container-resolvable by class name.
 */
class MarketScout extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Research the market landscape for the given topic.';
    }

    protected function reply(string $prompt): string
    {
        // For model behavior, generate MarketScout with make:agent and port these instructions.
        return "[MarketScout] Market notes for: {$prompt}\n"
            .'- Adjacent vendors: a few public ones, a long tail of private ones.'."\n"
            .'- Buyers are mid-market ops teams, slightly price sensitive.';
    }
}
