<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Application tool: turn retrieval text into a native embedding vector.
 *
 * Records the vector shape AND the embedding's own token usage in {@see $effects}
 * so a test can prove the nested modality usage is accounted at the tool/artifact
 * layer and is NOT folded into the outer agent's text usage.
 */
class EmbedTool implements Tool
{
    /** @var list<array<string, int>> */
    public static array $effects = [];

    public function description(): string
    {
        return 'Generate a native embedding vector for the given text.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['text' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        $response = Embeddings::for([(string) $request['text']])->generate();
        $vector = $response->first();

        self::$effects[] = [
            'dimensions' => count($vector),
            'input_tokens' => $response->usage->inputTokens,
        ];

        return 'embedded '.count($vector).' dimensions';
    }
}
