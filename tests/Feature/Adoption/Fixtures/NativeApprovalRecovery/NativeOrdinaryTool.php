<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class NativeOrdinaryTool implements Tool
{
    public function description(): string
    {
        return 'Record an ordinary controlled effect.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['value' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        NativeApprovalAgent::$effects[] = ['tool' => 'ordinary', 'id' => $request->toolCallId(), 'value' => $request['value']];

        return 'ordinary:'.$request['value'];
    }
}
