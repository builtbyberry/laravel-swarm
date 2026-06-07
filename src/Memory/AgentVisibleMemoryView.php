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
        $policy = $this->resolvePolicy($swarm);

        $candidates = $this->gatherCandidates($policy->scopes(), $swarm, $context, $agent);

        return $policy->present($candidates, $context, $agent);
    }

    /**
     * Gather candidate entries from exactly the scopes the policy declares, in
     * the declared order — so a policy never pays to load a scope it will not
     * look at, and the default policy (Run only) reads nothing extra.
     *
     * The Agent scope requires a concrete agent instance; callers that hold
     * only a class-string must resolve it before calling {@see present()}.
     * When {@see $agent} is null the Agent scope is skipped. The
     * Conversation scope keys on the conversation id bound to the run via
     * {@see RunContext::withConversationId()}; when no conversation id is bound
     * the scope has no id to key on and is skipped if declared.
     *
     * @param  array<int, MemoryScope>  $scopes
     * @return array<int, MemoryEntry>
     */
    protected function gatherCandidates(array $scopes, Swarm $swarm, RunContext $context, ?Agent $agent): array
    {
        $entries = [];

        foreach ($scopes as $scope) {
            $scopeId = match ($scope) {
                MemoryScope::Run => $context->runId,
                MemoryScope::Swarm => $swarm::class,
                MemoryScope::Agent => $agent !== null ? $agent::class : null,
                MemoryScope::Conversation => $context->conversationId(),
            };

            if ($scopeId === null) {
                continue;
            }

            $entries = [...$entries, ...$this->memory->all($scope, $scopeId)];
        }

        return $entries;
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
