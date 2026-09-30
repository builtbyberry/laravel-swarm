<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Swarms\NativeMediaRetrieval;

use {{ rootNamespace }}\Ai\Agents\NativeMediaRetrieval\AnswerSynthesizer;
use {{ rootNamespace }}\Ai\Agents\NativeMediaRetrieval\QueryPlanner;
use {{ rootNamespace }}\Ai\Agents\NativeMediaRetrieval\RetrievalAgent;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

/**
 * native-media-retrieval — native retrieval capabilities inside one workflow.
 *
 * Topology: Sequential. Three agents run in order to answer a question from a
 * knowledge base using Laravel AI's NATIVE retrieval capabilities — no bespoke
 * SDK glue:
 *
 *   QueryPlanner     — turns the question into a focused retrieval query.
 *   RetrievalAgent   — embeds the query, searches a native vector store, and
 *                      reranks the candidates (native Embeddings + Files/Stores
 *                      + Reranking). Returns the winning passages.
 *   AnswerSynthesizer — writes the final grounded answer from those passages.
 *
 * The shipped agents extend {@see \BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent}
 * so the pipeline runs end-to-end offline with NO provider or API key. Each agent's
 * docblock and the README show the exact native call to drop in for real behavior:
 * `Embeddings::for(...)`, a `FileSearch` provider tool over `Stores::create(...)`,
 * and `Reranking::of(...)`.
 *
 * Demonstrates: native retrieval (embeddings, vector stores, reranking) driven
 * from inside a Swarm workflow; Sequential topology; the Runnable trait; each
 * agent consuming the previous agent's output.
 *
 * Next step: docs/native-capabilities.md, docs/sequential.md
 */
#[Topology(TopologyEnum::Sequential)]
class MediaRetrievalPipeline implements Swarm
{
    use Runnable;

    /**
     * @return array<int, \Laravel\Ai\Contracts\Agent>
     */
    public function agents(): array
    {
        return [
            new QueryPlanner,
            new RetrievalAgent,
            new AnswerSynthesizer,
        ];
    }
}
