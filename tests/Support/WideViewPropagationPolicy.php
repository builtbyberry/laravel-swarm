<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Test policy that widens the agent-visible view to every candidate entry,
 * across all gatherable scopes, without dropping or reordering. Used to prove a
 * custom policy actually changes what a worker sees (and what is frozen).
 */
final class WideViewPropagationPolicy implements MemoryPropagationPolicy
{
    public function scopes(): array
    {
        return [MemoryScope::Run, MemoryScope::Agent, MemoryScope::Swarm];
    }

    public function present(array $candidateEntries, RunContext $context, ?Agent $agent): array
    {
        return $candidateEntries;
    }
}
