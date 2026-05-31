<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Default {@see MemoryPropagationPolicy}: presents the Run-scoped view only.
 *
 * This preserves pre-v0.10 behaviour exactly. Before this contract existed the
 * snapshot recorder froze `SwarmMemory::all(MemoryScope::Run, $runId)` and the
 * runners derived an agent's input from Run-scoped memory (`last_output`). This
 * policy reproduces that view: it drops every non-Run candidate and preserves
 * the order in which Run-scoped entries were gathered, so the frozen snapshot
 * is byte-identical to what live runners observed before propagation policy.
 */
final class DefaultPropagationPolicy implements MemoryPropagationPolicy
{
    public function present(array $candidateEntries, RunContext $context, ?Agent $agent): array
    {
        return array_values(array_filter(
            $candidateEntries,
            static fn (MemoryEntry $entry): bool => $entry->scope === MemoryScope::Run,
        ));
    }
}
