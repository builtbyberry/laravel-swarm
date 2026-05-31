<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Runners\SwarmAttributeResolver;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Container\Container;

/**
 * Builds the agent-visible memory view at the snapshot chokepoint.
 *
 * Runners hold everything the {@see MemoryPropagationPolicy} needs — the live
 * {@see RunContext}, the target {@see Agent}, and the {@see Swarm} — that the
 * snapshot recorder (keyed only by run id + step index) does not. This
 * collaborator gathers candidate entries across scopes, resolves the swarm's
 * propagation policy, applies it, and hands the runner the ordered, filtered
 * {@see MemoryEntry} list to freeze. The recorder stays a dumb persister and
 * third-party {@see SnapshotsMemory}
 * drivers inherit the policy for free because they only ever receive
 * already-filtered entries.
 *
 * @internal
 */
final class AgentVisibleMemoryView
{
    public function __construct(
        protected SwarmMemory $memory,
        protected SwarmAttributeResolver $resolver,
        protected Container $container,
    ) {}

    /**
     * Resolve the swarm's propagation policy and return the ordered subset of
     * memory entries the agent is permitted to see.
     *
     * @return array<int, MemoryEntry>
     */
    public function present(Swarm $swarm, RunContext $context, ?Agent $agent): array
    {
        $candidates = $this->gatherCandidates($swarm, $context, $agent);

        return $this->resolvePolicy($swarm)->present($candidates, $context, $agent);
    }

    /**
     * Gather candidate entries in a deterministic scope order: Run first (so
     * the default policy's filtered output matches the pre-v0.10 order), then
     * Agent and Swarm. The Conversation scope is intentionally omitted — the
     * runtime exposes no conversation handle to key it on. The non-Run scopes
     * are unpopulated by package code today; gathering them is a forward hook
     * for custom policies.
     *
     * @return array<int, MemoryEntry>
     */
    protected function gatherCandidates(Swarm $swarm, RunContext $context, ?Agent $agent): array
    {
        $entries = $this->memory->all(MemoryScope::Run, $context->runId);

        if ($agent !== null) {
            $entries = [...$entries, ...$this->memory->all(MemoryScope::Agent, $agent::class)];
        }

        return [...$entries, ...$this->memory->all(MemoryScope::Swarm, $swarm::class)];
    }

    protected function resolvePolicy(Swarm $swarm): MemoryPropagationPolicy
    {
        $class = $this->resolver->resolvePropagationPolicyClass($swarm);

        $policy = $this->container->make($class);

        if (! $policy instanceof MemoryPropagationPolicy) {
            throw new SwarmException(
                "Propagation policy [{$class}] must implement ".MemoryPropagationPolicy::class.'.',
            );
        }

        return $policy;
    }
}
