<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\CapabilityAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\GenerateImageTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\IndexAndSearchTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\NativeCapabilityWire;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Stores;
use RuntimeException;

const ARTIFACT_MARKER = 'IMAGE-BINARY-PAYLOAD-MARKER-0123456789';

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.history.driver', 'database');
    foreach (['inputs', 'outputs', 'artifacts', 'active_context'] as $capture) {
        config()->set('swarm.capture.'.$capture, true);
    }
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('ai.providers.gemini.key', 'test-key');
    config()->set('ai.conversations.generate_title', false);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Http::preventStrayRequests();
    GenerateImageTool::$effects = [];
    IndexAndSearchTool::$effects = [];
});

function fakeImageOf(string $bytes): void
{
    Image::fake([
        new ImageResponse(
            new Collection([new GeneratedImage(base64_encode($bytes), 'image/png')]),
            new ImageUsage(0, 1),
            new Meta('gemini', 'imagen-4'),
        ),
    ]);
}

function runImageArtifactWorkflow(): BuiltByBerry\LaravelSwarm\Responses\SwarmResponse
{
    $turns = 0;
    Http::fake(function (Request $request) use (&$turns) {
        $turns++;

        return Http::response($turns === 1
            ? NativeCapabilityWire::toolCall('GenerateImageTool', ['prompt' => 'a diagram'])
            : NativeCapabilityWire::message());
    });

    return app(SwarmRunner::class)->agent(new CapabilityAgent([new GenerateImageTool]))->prompt('task');
}

it('keeps generated image bytes on the disk and out of the persisted workflow payload', function () {
    Storage::fake(GenerateImageTool::DISK);
    fakeImageOf(ARTIFACT_MARKER);

    runImageArtifactWorkflow();

    $path = GenerateImageTool::$effects[0]['path'];

    // Bytes live on the application disk...
    Storage::disk(GenerateImageTool::DISK)->assertExists($path);
    expect(Storage::disk(GenerateImageTool::DISK)->get($path))->toBe(ARTIFACT_MARKER);

    // ...and neither the raw bytes nor their base64 wire form leak into any
    // persisted workflow payload column.
    $persisted = collect(DB::table('swarm_run_steps')->get())
        ->merge(DB::table('swarm_run_histories')->get())
        ->map(fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))
        ->implode("\n");

    expect($persisted)->not->toContain(ARTIFACT_MARKER)
        ->and($persisted)->not->toContain(base64_encode(ARTIFACT_MARKER))
        ->and(DB::table('swarm_run_steps')->count())->toBeGreaterThan(0);
});

it('does not over-capture media bytes when output capture is disabled', function () {
    Storage::fake(GenerateImageTool::DISK);
    foreach (['inputs', 'outputs', 'artifacts', 'active_context'] as $capture) {
        config()->set('swarm.capture.'.$capture, false);
    }
    fakeImageOf(ARTIFACT_MARKER);

    runImageArtifactWorkflow();

    // The artifact reference is still usable off the disk, and capture-off never
    // reintroduces the bytes into the persisted rows.
    $path = GenerateImageTool::$effects[0]['path'];
    expect(Storage::disk(GenerateImageTool::DISK)->get($path))->toBe(ARTIFACT_MARKER);

    $persisted = collect(DB::table('swarm_run_steps')->get())
        ->map(fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))
        ->implode("\n");
    expect($persisted)->not->toContain(base64_encode(ARTIFACT_MARKER));
});

it('surfaces an unavailable or expired vector store as an actionable workflow failure', function () {
    Stores::fake(function (string $storeId): void {
        throw new RuntimeException('vector store expired or unavailable: '.$storeId);
    });

    $turns = 0;
    Http::fake(function (Request $request) use (&$turns) {
        $turns++;

        return Http::response($turns === 1
            ? NativeCapabilityWire::toolCall('IndexAndSearchTool', ['document' => 'knowledge'])
            : NativeCapabilityWire::message());
    });

    expect(fn () => app(SwarmRunner::class)->agent(new CapabilityAgent([new IndexAndSearchTool]))->prompt('task'))
        ->toThrow(RuntimeException::class, 'vector store expired or unavailable');
});

it('leaves application-owned artifact disks untouched when swarm:prune runs', function () {
    Storage::fake(GenerateImageTool::DISK);
    fakeImageOf(ARTIFACT_MARKER);

    runImageArtifactWorkflow();
    $path = GenerateImageTool::$effects[0]['path'];

    // swarm:prune owns only Swarm-created persistence/temp files, never an
    // application-selected artifact disk.
    $exit = Artisan::call('swarm:prune');

    expect($exit)->toBe(0);
    Storage::disk(GenerateImageTool::DISK)->assertExists($path);
    expect(Storage::disk(GenerateImageTool::DISK)->get($path))->toBe(ARTIFACT_MARKER);
});
