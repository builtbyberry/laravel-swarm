<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\DefaultPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Declarative policy for which memory entries a worker agent sees when it is
 * invoked — by scope, key prefix, age, or any custom rule.
 *
 * Called at the snapshot chokepoint, immediately before a runner invokes an
 * agent. The policy receives every candidate entry the runner could surface
 * across scopes (Run and — when the application populates them — Conversation
 * / Agent / Swarm), the live {@see RunContext}, and the target {@see Agent},
 * and returns the ordered subset the agent is permitted to see. The frozen
 * {@see MemorySnapshot} mirrors exactly what
 * this policy returns, so the returned order is the snapshot's canonical order
 * and is preserved byte-for-byte into the persisted view.
 *
 * Bind a custom implementation in the service container to widen or reshape
 * the view globally, or override per swarm with the
 * `#[\BuiltByBerry\LaravelSwarm\Attributes\PropagationPolicy(MyPolicy::class)]`
 * attribute.
 *
 * Implementations MUST be pure with respect to the candidate list: they may
 * drop, reorder, or pass entries through, but MUST NOT fabricate entries or
 * mutate the readonly {@see MemoryEntry} value objects.
 *
 * The default binding ({@see DefaultPropagationPolicy})
 * presents the Run-scoped entries only, in the order received, preserving
 * pre-v0.10 behaviour exactly. Because no package code writes to the
 * Conversation / Agent / Swarm scopes during a run, the default view is
 * byte-identical to what live runners observed before this contract existed.
 */
interface MemoryPropagationPolicy
{
    /**
     * Declare which memory scopes this policy considers. The runner gathers
     * candidate entries from exactly these scopes — and no others — before
     * calling {@see present()}, so a policy never pays to load a scope it will
     * not look at.
     *
     * This is the coarse "what to load" half of the contract; {@see present()}
     * is the fine "what to show" half (drop, reorder, filter by key or age
     * within the gathered scopes). The default policy declares
     * `[MemoryScope::Run]` only.
     *
     * The {@see MemoryScope::Agent} scope is gathered only when the target
     * agent instance is known at invocation; on the durable and
     * hierarchical-parallel paths it is skipped even if declared. The
     * {@see MemoryScope::Conversation} scope is not yet gatherable (the runtime
     * exposes no conversation handle) and is ignored if declared.
     *
     * @return array<int, MemoryScope>
     */
    public function scopes(): array;

    /**
     * Filter and order the memory entries an agent sees at invocation time.
     *
     * `$agent` is null on execution paths that hold only the agent class-string
     * at freeze time (durable branches and hierarchical parallel workers).
     * Policies keying on the concrete agent instance must tolerate null there.
     *
     * @param  array<int, MemoryEntry>  $candidateEntries
     * @return array<int, MemoryEntry>
     */
    public function present(array $candidateEntries, RunContext $context, ?Agent $agent): array;
}
