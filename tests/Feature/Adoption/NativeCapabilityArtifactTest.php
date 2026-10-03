<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
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

/**
 * Runs the image workflow and captures what the tool result actually put ON THE
 * WIRE — the `function_call_output` fed back to the model on request 2. That is
 * the workflow payload a leaked binary would travel in, so tests assert against it.
 *
 * @return array{response: SwarmResponse, tool_output: string}
 */
function runImageArtifactWorkflow(): array
{
    $turns = 0;
    $toolOutput = '';
    Http::fake(function (Request $request) use (&$turns, &$toolOutput) {
        $turns++;
        if ($turns === 1) {
            return Http::response(NativeCapabilityWire::toolCall('GenerateImageTool', ['prompt' => 'a diagram']));
        }

        foreach ($request['input'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'function_call_output') {
                $toolOutput = (string) $item['output'];
            }
        }

        return Http::response(NativeCapabilityWire::message());
    });

    $response = app(SwarmRunner::class)->agent(new CapabilityAgent([new GenerateImageTool]))->prompt('task');

    return ['response' => $response, 'tool_output' => $toolOutput];
}

it('keeps generated image bytes on the disk and out of the persisted workflow payload', function () {
    Storage::fake(GenerateImageTool::DISK);
    fakeImageOf(ARTIFACT_MARKER);

    ['tool_output' => $toolOutput] = runImageArtifactWorkflow();

    $path = GenerateImageTool::$effects[0]['path'];

    // Bytes live on the application disk...
    Storage::disk(GenerateImageTool::DISK)->assertExists($path);
    expect(Storage::disk(GenerateImageTool::DISK)->get($path))->toBe(ARTIFACT_MARKER);

    // ...the workflow payload (the tool result on the wire) carries the stable
    // reference, NOT the bytes or their base64 form.
    expect($toolOutput)->toContain($path)
        ->and($toolOutput)->not->toContain(ARTIFACT_MARKER)
        ->and($toolOutput)->not->toContain(base64_encode(ARTIFACT_MARKER));

    // ...and nothing leaks into the persisted step/history columns either.
    $persisted = collect(DB::table('swarm_run_steps')->get())
        ->merge(DB::table('swarm_run_histories')->get())
        ->map(fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))
        ->implode("\n");

    expect($persisted)->not->toContain(ARTIFACT_MARKER)
        ->and($persisted)->not->toContain(base64_encode(ARTIFACT_MARKER))
        ->and(DB::table('swarm_run_steps')->count())->toBeGreaterThan(0);
});

it('redacts the native step under capture-off and still keeps media bytes off the payload', function () {
    Storage::fake(GenerateImageTool::DISK);
    foreach (['inputs', 'outputs', 'artifacts', 'active_context'] as $capture) {
        config()->set('swarm.capture.'.$capture, false);
    }
    fakeImageOf(ARTIFACT_MARKER);

    runImageArtifactWorkflow();

    // The artifact reference is still usable off the disk.
    $path = GenerateImageTool::$effects[0]['path'];
    expect(Storage::disk(GenerateImageTool::DISK)->get($path))->toBe(ARTIFACT_MARKER);

    $steps = DB::table('swarm_run_steps')->get();
    expect($steps)->not->toBeEmpty();

    // Capture genuinely engaged: the shipped-false flag maps to Redact, so the
    // step output and native result are redacted (this is what makes the guard
    // non-vacuous — it would fail if capture-off were a silent no-op).
    expect($steps->first()->native_result_status)->toBe('redacted')
        ->and($steps->first()->output)->toBe('[redacted]');

    // And regardless, neither the raw bytes nor their base64 form reach any column.
    $persisted = $steps->map(fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->implode("\n");
    expect($persisted)->not->toContain(ARTIFACT_MARKER)
        ->and($persisted)->not->toContain(base64_encode(ARTIFACT_MARKER));
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
