<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Reranking;
use Laravel\Ai\Tools\Request;

/**
 * Application tool: rerank retrieved candidate documents against the query with
 * the native reranking capability, returning the winning document.
 */
class RerankTool implements Tool
{
    /** @var list<string> */
    public const CANDIDATES = [
        'Swarm turns repeated multi-agent workflows into first-class objects.',
        'Laravel AI handles single-agent LLM interactions and workflow primitives.',
        'Reranking reorders candidate documents by relevance to a query.',
    ];

    /** @var list<array<string, mixed>> */
    public static array $effects = [];

    public function description(): string
    {
        return 'Rerank the retrieval candidates for the given query.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        $response = Reranking::of(self::CANDIDATES)->rerank((string) $request['query']);
        $top = $response->first();

        self::$effects[] = [
            'top_index' => $top?->index,
            'top_document' => $top?->document,
            'score' => $top?->score,
            'input_tokens' => $response->usage->inputTokens,
        ];

        return 'top: '.($top?->document ?? '(none)');
    }
}
