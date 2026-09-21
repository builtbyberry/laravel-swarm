<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;

class EffectTool extends ApprovalTool
{
    public static int $calls = 0;

    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }

    public function handle(Request $request): string
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new \RuntimeException('Tool effect inside a Swarm transaction.');
        }
        self::$calls++;

        return 'effect-done';
    }
}
