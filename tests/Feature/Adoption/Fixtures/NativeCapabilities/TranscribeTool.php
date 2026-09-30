<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Transcription;

/**
 * Application tool: transcribe a base64 audio clip to text with the native
 * transcription capability.
 */
class TranscribeTool implements Tool
{
    /** @var list<array<string, mixed>> */
    public static array $effects = [];

    public function description(): string
    {
        return 'Transcribe the given base64 audio clip to text.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'audio_base64' => $schema->string()->required(),
            'mime' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $response = Transcription::fromBase64((string) $request['audio_base64'], (string) $request['mime'])->generate();

        self::$effects[] = [
            'text' => $response->text,
            'audio_seconds' => $response->usage->audioSeconds,
        ];

        return 'transcript: '.$response->text;
    }
}
