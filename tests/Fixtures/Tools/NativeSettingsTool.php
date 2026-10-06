<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class NativeSettingsTool implements Tool
{
    public static int $constructions = 0;

    public static bool $failConstruction = false;

    public function __construct(public readonly string $tenant = 'default')
    {
        self::$constructions++;

        if (self::$failConstruction) {
            throw new \RuntimeException('Native settings tool construction is disabled.');
        }
    }

    public function description(): string
    {
        return 'A reconstructible native settings test tool.';
    }

    public function handle(Request $request): string
    {
        return $this->tenant;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
