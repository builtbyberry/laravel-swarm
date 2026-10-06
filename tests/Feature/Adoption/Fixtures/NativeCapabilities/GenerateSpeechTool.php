<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Audio;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Application tool: synthesize speech from text with the native audio (TTS)
 * capability and persist it to an application disk, returning a stable artifact
 * reference rather than the audio bytes.
 */
class GenerateSpeechTool implements Tool
{
    public const DISK = 'native-artifacts';

    /** @var list<array<string, mixed>> */
    public static array $effects = [];

    public function description(): string
    {
        return 'Synthesize speech audio from the given text.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['text' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        $response = Audio::of((string) $request['text'])->generate();
        $path = $response->store('speech', self::DISK);

        self::$effects[] = ['disk' => self::DISK, 'path' => $path];

        return 'speech:'.$path;
    }
}
