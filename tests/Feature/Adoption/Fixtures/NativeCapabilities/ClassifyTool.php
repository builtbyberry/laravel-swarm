<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Tools\Request;

/**
 * Application tool: classify retrieval text into a routing label with the native
 * classification capability.
 */
class ClassifyTool implements Tool
{
    /** @var list<array<string, mixed>> */
    public static array $effects = [];

    public function description(): string
    {
        return 'Classify the text into a routing label.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['text' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        $response = Classification::of((string) $request['text'])
            ->question('label', new Choice('Route this message to a team.', [
                'support' => 'A customer support or troubleshooting request.',
                'sales' => 'A sales or pricing inquiry.',
            ]))
            ->classify();

        $answer = $response->answer('label');
        $choice = $answer instanceof ChoiceAnswer ? $answer->choice : null;

        self::$effects[] = ['choice' => $choice];

        return 'label: '.($choice ?? '(none)');
    }
}
