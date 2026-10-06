<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\NativeApprovalRecovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class CrashApprovalTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Record one controlled non-idempotent proof effect.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['value' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        if (CrashBarrier::matches('before_tool_effect')) {
            CrashBarrier::trip('before_tool_effect');
        }

        DB::table('native_approval_proof_effects')->insert([
            'operation_key' => 'native-approval-effect',
            'value' => (string) $request['value'],
            'created_at' => now('UTC'),
        ]);

        if (CrashBarrier::matches('after_effect_before_result')) {
            CrashBarrier::trip('after_effect_before_result');
        }

        return 'effect:'.$request['value'];
    }
}
