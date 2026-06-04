<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Test policy that explicitly declares the Conversation scope (alongside Run) so
 * a test can prove the runtime *skips* it: `AgentVisibleMemoryView` resolves the
 * Conversation scope_id to null and never gathers it, even when a policy asks for
 * it. `present()` returns candidates unchanged, so anything the view does gather
 * surfaces — making the absence of Conversation entries an assertion about the
 * skip, not about the policy filtering them out.
 */
final class ConversationDeclaringPropagationPolicy implements MemoryPropagationPolicy
{
    public function scopes(): array
    {
        return [MemoryScope::Run, MemoryScope::Conversation];
    }

    public function present(array $candidateEntries, RunContext $context, ?Agent $agent): array
    {
        return $candidateEntries;
    }
}
