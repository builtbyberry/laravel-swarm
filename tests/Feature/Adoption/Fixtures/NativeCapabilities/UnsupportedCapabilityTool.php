<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Image;
use Laravel\Ai\Tools\Request;

/**
 * Application tool that requests a native capability on a provider that does not
 * offer it (image generation on Anthropic). Used to prove that a native
 * provider/model limitation fails loud from INSIDE a workflow — Swarm surfaces
 * it unchanged rather than degrading silently.
 */
class UnsupportedCapabilityTool implements Tool
{
    /** @var list<string> */
    public static array $effects = [];

    public function description(): string
    {
        return 'Attempt an image generation on a provider that does not support it.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['prompt' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        // Anthropic does not support image generation; this throws before any wire.
        $response = Image::of((string) $request['prompt'])->generate('anthropic');

        self::$effects[] = 'unexpected-success';

        return 'image generated';
    }
}
