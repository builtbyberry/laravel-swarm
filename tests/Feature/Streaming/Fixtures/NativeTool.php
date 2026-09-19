<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;

class NativeTool implements Tool
{
    public static int $calls = 0;

    public function description(): string
    {
        return 'Exercise the native tool handler boundary.';
    }

    public function handle(Request $request): string
    {
        self::$calls++;

        return match (config('tests.native_parity.tool')) {
            'validation' => throw ValidationException::withMessages(['value' => 'validation-secret']),
            'throwable' => throw new RuntimeException('tool-secret'),
            default => 'tool-result-secret',
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return ['value' => $schema->string()];
    }
}
