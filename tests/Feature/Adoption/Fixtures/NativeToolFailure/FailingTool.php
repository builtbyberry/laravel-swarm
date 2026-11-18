<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeToolFailure;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;

/**
 * Application tool whose handler throws, for the native tool-failure policy
 * proofs (#634). The thrown type and message are asserted directly, so Swarm is
 * proven to surface the handler's own exception rather than laravel/ai 1.4's
 * internal ToolExecutionException wrapper.
 */
class FailingTool implements Tool
{
    public const MESSAGE = 'native tool handler failed';

    public static int $calls = 0;

    public function description(): string
    {
        return 'Always fails.';
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

        throw new RuntimeException(self::MESSAGE);
    }
}
