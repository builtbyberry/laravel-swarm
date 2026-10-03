<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class NativeApprovalTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Record a controlled effect after approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['value' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        NativeApprovalAgent::$effects[] = ['tool' => 'approve', 'id' => $request->toolCallId(), 'value' => $request['value']];

        return 'approved:'.$request['value'];
    }
}
