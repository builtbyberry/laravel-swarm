<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class NativeSettingsTool implements Tool
{
    public function __construct(public readonly string $tenant = 'default') {}

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
