<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class NativeRejectableApprovalTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Never run when its approval is rejected.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['value' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        NativeApprovalAgent::$effects[] = ['tool' => 'reject', 'id' => $request->toolCallId(), 'value' => $request['value']];

        return 'should-not-run';
    }
}
