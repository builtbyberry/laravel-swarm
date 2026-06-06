<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\ConversationRunResolver;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Default {@see ConversationRunResolver}: resolves every conversation to an
 * empty run list.
 *
 * This is the honest no-op default: although a run can now carry a conversation
 * handle ({@see RunContext::withConversationId()}),
 * Swarm records no queryable link from a conversation back to its runs in its
 * own tables, so the package cannot expand a conversation generically. With
 * this resolver bound, `swarm:memory:dump <conversation-id>` exports only the
 * Conversation-scoped entries and reports `runs_expanded: false` in the
 * envelope so an auditor is never misled into reading a non-expanded export as
 * complete.
 *
 * Applications that know their own conversation/run topology bind a real
 * implementation in place of this one.
 */
final class NullConversationRunResolver implements ConversationRunResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $conversationId): array
    {
        return [];
    }
}
