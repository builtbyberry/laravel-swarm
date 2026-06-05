<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Memory\NullConversationRunResolver;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Resolves the set of run ids that belong to a conversation.
 *
 * `swarm:memory:dump <conversation-id>` needs to expand a conversation into
 * its constituent runs so an audit/DSAR export can carry every run-scoped
 * memory entry and snapshot recorded under that conversation — not just the
 * Conversation-scoped entries.
 *
 * The package itself cannot do that expansion generically. A run now carries a
 * first-class conversation handle
 * ({@see RunContext::withConversationId()},
 * as of v0.12), but that id lives in the run's metadata map, not an indexed
 * column: `swarm_memories` Conversation rows carry `run_id = NULL`, and
 * `swarm_run_histories` exposes no queryable `conversation_id` column. There is
 * therefore still no first-class, queryable link from a conversation to its
 * runs inside Swarm's own tables.
 *
 * This contract is the seam that lets an application — which DOES know its
 * own conversation/run topology — teach the dump command how to expand a
 * conversation, without any breaking change to the command. The bundled
 * default is {@see NullConversationRunResolver},
 * which resolves to an empty list; bind your own implementation in the
 * container to light up run expansion:
 *
 *     $this->app->singleton(
 *         ConversationRunResolver::class,
 *         AppConversationRunResolver::class,
 *     );
 *
 * The `string -> list<string>` signature is stable: a conversation id maps to
 * the ordered run ids recorded under it.
 */
interface ConversationRunResolver
{
    /**
     * Return the run ids recorded under the given conversation id.
     *
     * Implementations should return an empty list when the conversation is
     * unknown rather than throwing. Order is preserved by the dump command for
     * deterministic, auditable output, so return ids in a stable order
     * (e.g. chronological) when possible.
     *
     * @return list<string>
     */
    public function resolve(string $conversationId): array;
}
