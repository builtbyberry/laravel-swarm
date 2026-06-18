<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Concerns\RemembersRunContext;
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Support\Collection;

/**
 * Opt-in {@see MemoryPropagationPolicy} that surfaces the reserved
 * `swarm:step.{n}.output` keys as an ordered, turn-by-turn transcript.
 *
 * This is the policy to pair with the
 * {@see RemembersRunContext} trait: the
 * trait renders the agent-visible view as laravel/ai messages, and this policy
 * makes that view the run's prior step outputs in step order. The default
 * {@see DefaultPropagationPolicy} hides those keys, so without this policy the
 * trait renders nothing.
 *
 * Because the snapshot freezes the view a runner presents *before* invoking an
 * agent, step `k` sees outputs for steps `0..k-1` only — never its own (not yet
 * produced) and never a later step's. The final step's output is therefore not
 * surfaced to any agent within the same run; it lives in raw Run memory (e.g.
 * `swarm:memory:dump`) like every step output.
 *
 * `$includeRunMemory` controls what accompanies the transcript:
 *   false (default) — transcript only: just the ordered step outputs.
 *   true            — transcript followed by the remaining Run-scoped entries
 *                     (the default view: `last_output`, user-written keys).
 *
 * Bind globally via `swarm.memory.propagation_policy`, or per swarm with
 * `#[\BuiltByBerry\LaravelSwarm\Attributes\PropagationPolicy(...)]`. The
 * service container resolves the configured `include_run_memory` flag; see
 * the binding in `SwarmServiceProvider`.
 */
final class ConversationPropagationPolicy implements MemoryPropagationPolicy
{
    public function __construct(
        private readonly bool $includeRunMemory = false,
    ) {}

    public function scopes(): array
    {
        return [MemoryScope::Run];
    }

    public function present(array $candidateEntries, RunContext $context, ?Agent $agent): array
    {
        $partitioned = collect($candidateEntries)
            ->filter(static fn (MemoryEntry $entry): bool => $entry->scope === MemoryScope::Run)
            ->partition(static fn (MemoryEntry $entry): bool => SwarmMemoryKeys::isStepOutput($entry->key));

        /** @var Collection<int, MemoryEntry> $transcriptEntries */
        $transcriptEntries = $partitioned->first();
        /** @var Collection<int, MemoryEntry> $restEntries */
        $restEntries = $partitioned->last();

        $transcript = $transcriptEntries
            // partition(isStepOutput) guarantees every entry here has a valid step-output
            // key, so stepIndexOf() is non-null; ?int reflects the method's declared return.
            ->sortBy(static fn (MemoryEntry $entry): ?int => SwarmMemoryKeys::stepIndexOf($entry->key))
            ->values()
            ->all();

        if (! $this->includeRunMemory) {
            return $transcript;
        }

        return array_merge($transcript, $restEntries->values()->all());
    }
}
