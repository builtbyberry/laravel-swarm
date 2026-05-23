<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

/**
 * Addressing scope for a Swarm memory entry.
 *
 * Every memory entry lives in exactly one scope. The scope determines the
 * lifetime and visibility of the entry and pairs with a `scope_id` (the
 * concrete run id, conversation id, agent class, or swarm class) to form the
 * full address.
 *
 * - `Run`          — bounded to a single swarm run. Cleared with the run.
 * - `Conversation` — shared across runs in the same conversation thread.
 * - `Agent`        — per-agent-class persistent state (knowledge, preferences).
 * - `Swarm`        — shared across all agents in a swarm class.
 *
 * Propagation policy (v0.10) decides which scopes a worker agent sees when
 * invoked. Capture policy applies redaction uniformly across scopes.
 */
enum MemoryScope: string
{
    case Run = 'run';

    case Conversation = 'conversation';

    case Agent = 'agent';

    case Swarm = 'swarm';
}
