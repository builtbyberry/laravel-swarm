<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files;
use Laravel\Ai\Stores;
use Laravel\Ai\Tools\Request;

/**
 * Application tool: store a document as a native provider file, index it into a
 * native vector store, and confirm retrieval by id — proving the files/vector
 * store family with STABLE references (file id, store id) rather than payloads.
 */
class IndexAndSearchTool implements Tool
{
    public const STORE_NAME = 'workflow-kb';

    public const FILE_NAME = 'kb.txt';

    /** @var list<array<string, mixed>> */
    public static array $effects = [];

    public function description(): string
    {
        return 'Index a document into a native vector store and confirm retrieval.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['document' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        $stored = Files::put((string) $request['document'], 'text/plain', self::FILE_NAME);
        $file = Files::get($stored->id);
        $store = Stores::create(self::STORE_NAME, fileIds: [$stored->id]);
        $fetched = Stores::get($store->id);

        self::$effects[] = [
            'file_id' => $stored->id,
            'file_mime' => $file->mimeType(),
            'store_id' => $store->id,
            'fetched_id' => $fetched->id,
            'ready' => $fetched->ready,
        ];

        return 'indexed:'.$store->id;
    }
}
