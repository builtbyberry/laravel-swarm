<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\DefaultPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Test policy that gathers candidates from every scope but presents only the
 * single allow-listed key. It proves a custom policy actively *drops* gathered
 * candidates — including a Run-scoped entry whose key is not allow-listed, which
 * neither {@see DefaultPropagationPolicy}
 * (drops by scope) nor {@see WideViewPropagationPolicy} (drops nothing) can
 * demonstrate. The wide scope set makes the durable-branch path (which gathers
 * the Agent scope because it knows the concrete agent) observable too.
 */
final class RestrictivePropagationPolicy implements MemoryPropagationPolicy
{
    public const ALLOWED_KEY = 'allowed-note';

    public function scopes(): array
    {
        return [MemoryScope::Run, MemoryScope::Agent, MemoryScope::Swarm];
    }

    public function present(array $candidateEntries, RunContext $context, ?Agent $agent): array
    {
        return array_values(array_filter(
            $candidateEntries,
            static fn (MemoryEntry $entry): bool => $entry->key === self::ALLOWED_KEY,
        ));
    }
}
