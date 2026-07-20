<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Thrown when a structured-output agent is placed on a streaming path (#321).
 *
 * `laravel/ai` cannot stream an agent implementing {@see HasStructuredOutput} —
 * a structured-output agent produces a single parsed object, not an incremental
 * token stream — so `$agent->stream()` throws an upstream `InvalidArgumentException`
 * with no swarm context. This is the swarm-domain guard: it detects the case
 * *before* the vendor call (at every worker `stream()` site, and at durable
 * dispatch) and fails loud with a message that names the node, the agent, and the
 * remedy, so the operator sees what they did and how to fix it — never a bare
 * vendor error.
 *
 * The coordinator of a hierarchical swarm legitimately implements
 * `HasStructuredOutput` (it plans), but it runs via `prompt()` and is never
 * streamed, so it never reaches this guard.
 */
class StructuredOutputStreamingException extends SwarmException
{
    /**
     * Guard a single agent about to be streamed. A no-op unless the agent is a
     * structured-output agent, in which case it fails loud. Detection and message
     * live here so every `stream()` call site guards identically (no drift).
     */
    public static function guard(Agent $agent, ?string $nodeLabel = null): void
    {
        if ($agent instanceof HasStructuredOutput) {
            throw self::forAgent($agent::class, $nodeLabel);
        }
    }

    public static function forAgent(string $agentClass, ?string $nodeLabel = null): self
    {
        $where = $nodeLabel !== null
            ? "Node [{$nodeLabel}] ({$agentClass})"
            : "Agent [{$agentClass}]";

        return new self(
            "{$where} implements Laravel AI structured output (HasStructuredOutput) and cannot be streamed. "
            .'A structured-output agent produces a single parsed object, not an incremental token stream. '
            .'Remove HasStructuredOutput from the agent, or run this node without streaming '
            .'(use run()/prompt(), or drop #[DurableStreaming] from the swarm).'
        );
    }
}
