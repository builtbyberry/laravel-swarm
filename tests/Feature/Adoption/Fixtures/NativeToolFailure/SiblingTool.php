<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeToolFailure;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Second application tool called in the SAME step as {@see FailingTool}.
 *
 * laravel/ai executes every tool call in a step before yielding any result, so
 * this tool's call count is what distinguishes propagating from inside
 * laravel/ai's catch block (this never runs, matching laravel/ai 1.0-1.3) from
 * deferring the throw to a later Swarm boundary (this runs its side effect
 * first).
 */
class SiblingTool implements Tool
{
    public static int $calls = 0;

    public function description(): string
    {
        return 'Records that it ran.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['value' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        self::$calls++;

        return 'sibling-ran';
    }
}
