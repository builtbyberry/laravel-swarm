<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\NativeMediaRetrieval;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Step 2 of native-media-retrieval: retrieve and rank passages for the query
 * using Laravel AI's NATIVE retrieval capabilities.
 *
 * Extends ScriptedAgent so the example runs offline. In production, retrieve with
 * the native capabilities. The idiomatic native path binds a vector store to the
 * agent with the `FileSearch` provider tool and lets the model retrieve — declare
 * it on a Promptable agent's tools():
 *
 *     use Laravel\Ai\Providers\Tools\FileSearch;
 *
 *     public function tools(): array
 *     {
 *         return [new FileSearch([config('services.kb.store_id')])];
 *     }
 *
 * For app-side retrieval instead, embed the query and rerank candidates you fetch
 * yourself:
 *
 *     $vector = Embeddings::for([$query])->generate()->first();
 *     $candidates = $this->fetchCandidates($vector);   // your app's ANN/SQL search
 *     $ranked = Reranking::of($candidates)->limit(3)->rerank($query);
 *
 * See docs/native-capabilities.md for the full recipe. (`Stores` manages a store's
 * documents — add/remove/get — and is searched through the `FileSearch` tool, not a
 * query method on the store object.)
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
