<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunRecord;

/**
 * Resolves the concrete `(scope, scopeId)` address a memory tool should read or
 * write, given the requested {@see MemoryScope} and the ambient
 * {@see ActiveRunContext}.
 *
 * The {@see Recall} and {@see Remember} tools accept a scope *name* from the
 * model but never a scope *id* — the id is framework-owned and is resolved here
 * from the active run so an agent can never address another run's, swarm's, or
 * agent's memory by guessing an id.
 *
 * Resolution mirrors {@see AgentVisibleMemoryView}'s candidate gathering:
 *
 *  - {@see MemoryScope::Run}          — the active run id.
 *  - {@see MemoryScope::Swarm}        — the active swarm class-string.
 *  - {@see MemoryScope::Agent}        — the bound agent's class-string, when the
 *                                       tool was constructed for a specific
 *                                       agent; otherwise unresolvable.
 *  - {@see MemoryScope::Conversation} — never resolvable yet (the runtime
 *                                       exposes no conversation handle, the same
 *                                       gap the view documents).
 *
 * Returns null when the requested scope cannot be addressed in the current
 * context (no active run, or a scope whose id is not in hand). Callers treat
 * null as a graceful "memory is not available for that scope right now" rather
 * than an error, so the tools stay safe outside a swarm run.
 *
 * @internal
 */
final class MemoryToolScopeResolver
{
    public function __construct(
        private readonly ?Agent $agent = null,
    ) {}

    public function resolve(MemoryScope $scope): ?ResolvedMemoryScope
    {
        $record = ActiveRunContext::current();

        // Every scope is run-scoped in practice: without an active run there is
        // no run/swarm id to address, and the Agent scope must not be writable
        // from a bound agent alone (that would let an agent persist outside any
        // run). So a missing record disqualifies every scope uniformly.
        if ($record === null) {
            return null;
        }

        $scopeId = $this->scopeIdFor($scope, $record);

        if ($scopeId === null) {
            return null;
        }

        return new ResolvedMemoryScope($scope, $scopeId);
    }

    private function scopeIdFor(MemoryScope $scope, ActiveRunRecord $record): ?string
    {
        return match ($scope) {
            MemoryScope::Run => $record->runId,
            MemoryScope::Swarm => $record->swarmClass,
            MemoryScope::Agent => $this->agent !== null ? $this->agent::class : null,
            MemoryScope::Conversation => null,
        };
    }
}
