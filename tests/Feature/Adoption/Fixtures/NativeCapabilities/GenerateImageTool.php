<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Image;
use Laravel\Ai\Tools\Request;

/**
 * Application tool: generate a native image and persist it to an application disk,
 * returning a STABLE artifact reference (a disk path) rather than base64 bytes.
 *
 * Proves the large-binary-outside-the-workflow-payload rule: only the path is
 * threaded through the workflow; the bytes live on the app-selected disk.
 */
class GenerateImageTool implements Tool
{
    public const DISK = 'native-artifacts';

    /** @var list<array<string, mixed>> */
    public static array $effects = [];

    public function description(): string
    {
        return 'Generate an illustrative image and store it as a workflow artifact.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['prompt' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        $response = Image::of((string) $request['prompt'])->generate();
        $path = $response->store('generated', self::DISK);

        self::$effects[] = [
            'disk' => self::DISK,
            'path' => $path,
            'mime' => $response->firstImage()->mime(),
        ];

        return 'artifact:'.$path;
    }
}
