<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\NativeMediaRetrieval;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Step 1 of native-media-retrieval: turn the user's question into a focused
 * retrieval query the next agent can embed and search with.
 *
 * Extends ScriptedAgent so the example runs offline with no provider. For model
 * behavior, generate this agent with Laravel AI's `make:agent` and keep the
 * instructions below.
 */
class QueryPlanner extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Rewrite the user question into a short, keyword-focused retrieval query. Return only the query text.';
    }

    protected function reply(string $prompt): string
    {
        // Deterministic offline stand-in: strip filler words so the demo query
        // visibly reflects the question. A real provider returns the same shape.
        $query = preg_replace('/\b(what|which|how|is|are|the|a|an|of|to|do|does|please|tell|me|about)\b/i', '', strtolower($prompt));

        return trim((string) preg_replace('/\s+/', ' ', (string) $query));
    }
}
