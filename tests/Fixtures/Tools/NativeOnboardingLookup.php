<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class NativeOnboardingLookup implements Tool
{
    public static int $calls = 0;

    /** @var array<string, mixed> */
    public static array $arguments = [];

    public static function reset(): void
    {
        self::$calls = 0;
        self::$arguments = [];
    }

    public function name(): string
    {
        return 'lookup_release_notes';
    }

    public function description(): string
    {
        return 'Look up deterministic release-note facts for the onboarding test.';
    }

    public function handle(Request $request): string
    {
        self::$calls++;
        self::$arguments = $request->all();

        return 'v0.28.0 makes native Laravel AI agents the normal authoring path.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'release' => $schema->string()->required(),
        ];
    }
}
