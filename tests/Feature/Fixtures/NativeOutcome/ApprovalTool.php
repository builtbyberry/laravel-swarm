<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ApprovalTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public static int $calls = 0;

    public function description(): string
    {
        return 'An effect requiring approval.';
    }

    public function handle(Request $request): string
    {
        self::$calls++;

        return 'effect';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['value' => $schema->string()->required()];
    }
}
