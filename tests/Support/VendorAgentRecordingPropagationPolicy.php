<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Laravel\Ai\Contracts\Agent;

/**
 * Records the agent instances handed to {@see present()}.
 *
 * Regression coverage for the one breaking change in v0.23.0:
 * {@see MemoryPropagationPolicy::present()} widened its agent parameter to the
 * vendor contract, which was required for a plain `laravel/ai` agent to reach a
 * propagation policy at all. This policy is type-hinted against the vendor
 * contract — as third-party policies must now be — and asserts it actually
 * receives the concrete agent rather than `null`.
 *
 * Without the widening, a vendor-only agent reaching this method raises a
 * TypeError; if someone later narrows the parameter back to the swarm marker,
 * the accompanying test fails instead of silently breaking vendor agents at the
 * memory chokepoint.
 */
final class VendorAgentRecordingPropagationPolicy implements MemoryPropagationPolicy
{
    /** @var array<int, ?Agent> */
    public static array $seenAgents = [];

    public static function reset(): void
    {
        self::$seenAgents = [];
    }

    public function scopes(): array
    {
        return [MemoryScope::Run, MemoryScope::Agent, MemoryScope::Swarm];
    }

    public function present(array $candidateEntries, RunContext $context, ?Agent $agent): array
    {
        self::$seenAgents[] = $agent;

        return $candidateEntries;
    }
}
