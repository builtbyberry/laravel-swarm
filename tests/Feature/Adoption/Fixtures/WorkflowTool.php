<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class WorkflowTool implements Tool
{
    public static array $effects = [];

    public function description(): string
    {
        return 'Record the controlled effect.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['value' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        self::$effects[] = $request['value'];

        return 'effect:'.$request['value'];
    }
}
