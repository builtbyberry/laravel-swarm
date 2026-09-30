<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\NativeMediaRetrieval;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Step 2 of native-media-retrieval: retrieve and rank passages for the query
 * using Laravel AI's NATIVE retrieval capabilities.
 *
 * Extends ScriptedAgent so the example runs offline. In production this agent
 * uses the native capabilities directly — swap {@see reply()} for a real
 * provider path shaped like this:
 *
 *     // Embed the query for a similarity search.
 *     $vector = Embeddings::for([$query])->generate()->first();
 *
 *     // Search a native vector store, then rerank the candidates.
 *     $store = Stores::get(config('services.kb.store_id'));
 *     $candidates = $this->searchStore($store, $vector);   // your similarity search
 *     $ranked = Reranking::of($candidates)->limit(3)->rerank($query);
 *
 *     return json_encode($ranked->documents());
 *
 * Or bind the store to a native agent with the `FileSearch` provider tool and
 * let the model retrieve. See docs/native-capabilities.md for the full recipe.
 */
class RetrievalAgent extends ScriptedAgent
{
    /**
     * A tiny offline knowledge base standing in for a native vector store.
     *
     * @var list<string>
     */
    private const KNOWLEDGE_BASE = [
        'Laravel Swarm turns repeated multi-agent workflows into first-class application objects.',
        'Native retrieval in Swarm uses Laravel AI embeddings, vector stores and reranking.',
        'Swarm workflows run in sync, queued, streamed and durable execution modes.',
    ];

    public function instructions(): string
    {
        return 'Retrieve the most relevant knowledge-base passages for the query and return them ranked, most relevant first.';
    }

    protected function reply(string $prompt): string
    {
        // Deterministic offline stand-in for embed -> vector search -> rerank:
        // score each passage by shared query terms so the demo reflects its input.
        $terms = array_filter(explode(' ', strtolower($prompt)));
        $ranked = collect(self::KNOWLEDGE_BASE)
            ->map(fn (string $passage): array => [
                'passage' => $passage,
                'score' => count(array_filter($terms, fn (string $t): bool => str_contains(strtolower($passage), $t))),
            ])
            ->sortByDesc('score')
            ->take(2)
            ->pluck('passage')
            ->values()
            ->all();

        return (string) json_encode(['passages' => $ranked], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
