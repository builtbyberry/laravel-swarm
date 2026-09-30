<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\CapabilityAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\ClassifyTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\EmbedTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\GenerateImageTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\IndexAndSearchTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\NativeCapabilityWire;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\RerankTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\TranscribeTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Classification;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Files;
use Laravel\Ai\Image;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\Data\RerankingUsage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\RerankingResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Stores;
use Laravel\Ai\Transcription;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('ai.providers.gemini.key', 'test-key');
    config()->set('ai.providers.cohere.key', 'test-key');
    config()->set('ai.conversations.generate_title', false);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Http::preventStrayRequests();
    EmbedTool::$effects = [];
    RerankTool::$effects = [];
    ClassifyTool::$effects = [];
    GenerateImageTool::$effects = [];
    TranscribeTool::$effects = [];
    IndexAndSearchTool::$effects = [];
});

/**
 * Drive the outer agent's text turn over faked wire: request 1 calls the tool,
 * request 2 settles with a final message. The nested native modality is faked
 * at its own gateway by the caller before invoking this.
 *
 * @param  array<string, mixed>  $arguments
 */
function runCapabilityWorkflow(object $tool, string $toolName, array $arguments): BuiltByBerry\LaravelSwarm\Responses\SwarmResponse
{
    $requests = 0;
    Http::fake(function (Request $request) use (&$requests, $toolName, $arguments) {
        $requests++;
        if ($requests === 1) {
            expect($request['tools'][0]['name'])->toBe($toolName);

            return Http::response(NativeCapabilityWire::toolCall($toolName, $arguments));
        }

        return Http::response(NativeCapabilityWire::message());
    });

    /** @var \Laravel\Ai\Contracts\Tool $tool */
    return app(SwarmRunner::class)->agent(new CapabilityAgent([$tool]))->prompt('task');
}

it('runs a native embedding inside a workflow and keeps its usage off the outer text total', function () {
    Embeddings::fake([
        new EmbeddingsResponse([[0.1, 0.2, 0.3, 0.4]], new Usage(11, 0), new Meta('openai', 'text-embedding-3-small')),
    ]);

    $response = runCapabilityWorkflow(new EmbedTool, 'EmbedTool', ['text' => 'retrieve me']);

    // The nested modality really ran and its typed result surfaced at the tool layer.
    expect(EmbedTool::$effects)->toBe([['dimensions' => 4, 'input_tokens' => 11]]);

    // AC3: the outer text usage is the wire's 2+2 / 3+3 across the two turns and
    // does NOT absorb the 11 embedding input tokens.
    expect($response->usage['input_tokens'])->toBe(4)
        ->and($response->usage['output_tokens'])->toBe(6)
        ->and($response->output)->toBe('workflow-complete');
    Http::assertSentCount(2);
});

it('runs native reranking inside a workflow and surfaces the ranked winner', function () {
    Reranking::fake([
        new RerankingResponse([
            new RankedDocument(2, RerankTool::CANDIDATES[2], 0.91),
            new RankedDocument(0, RerankTool::CANDIDATES[0], 0.42),
        ], new RerankingUsage(7), new Meta('cohere', 'rerank-v3.5')),
    ]);

    $response = runCapabilityWorkflow(new RerankTool, 'RerankTool', ['query' => 'what is reranking']);

    expect(RerankTool::$effects)->toHaveCount(1)
        ->and(RerankTool::$effects[0]['top_index'])->toBe(2)
        ->and(RerankTool::$effects[0]['top_document'])->toBe(RerankTool::CANDIDATES[2])
        ->and(RerankTool::$effects[0]['score'])->toBe(0.91)
        ->and($response->output)->toBe('workflow-complete');
    Http::assertSentCount(2);
});

it('runs native classification inside a workflow and surfaces the chosen label', function () {
    Classification::fake([
        new ClassificationResponse([
            'label' => new ChoiceAnswer('support', ['support' => 0.8, 'sales' => 0.2]),
        ], new TextUsage(5, 1), new Meta('typesafe', 'default')),
    ]);

    $response = runCapabilityWorkflow(new ClassifyTool, 'ClassifyTool', ['text' => 'my order is broken']);

    expect(ClassifyTool::$effects)->toBe([['choice' => 'support']])
        ->and($response->output)->toBe('workflow-complete');
    Http::assertSentCount(2);
});

it('generates a native image inside a workflow and threads a stable artifact reference', function () {
    Storage::fake(GenerateImageTool::DISK);
    Image::fake([
        new ImageResponse(
            new Collection([new GeneratedImage(base64_encode('binary-image-bytes'), 'image/png')]),
            new ImageUsage(0, 1),
            new Meta('gemini', 'imagen-4'),
        ),
    ]);

    $response = runCapabilityWorkflow(new GenerateImageTool, 'GenerateImageTool', ['prompt' => 'a diagram']);

    expect(GenerateImageTool::$effects)->toHaveCount(1);
    $artifact = GenerateImageTool::$effects[0];

    // A stable reference (path), not the bytes, is what the workflow carries.
    expect($artifact['disk'])->toBe(GenerateImageTool::DISK)
        ->and($artifact['mime'])->toBe('image/png')
        ->and($artifact['path'])->toBeString();
    Storage::disk(GenerateImageTool::DISK)->assertExists($artifact['path']);
    expect(Storage::disk(GenerateImageTool::DISK)->get($artifact['path']))->toBe('binary-image-bytes');
    Http::assertSentCount(2);
});

it('transcribes native audio inside a workflow and surfaces the typed transcript', function () {
    Transcription::fake([
        new TranscriptionResponse('the quarterly report is ready', new Collection, new TranscriptionUsage(0, 0, audioSeconds: 3.5), new Meta('openai', 'whisper-1')),
    ]);

    $response = runCapabilityWorkflow(new TranscribeTool, 'TranscribeTool', [
        'audio_base64' => base64_encode('fake-audio-bytes'),
        'mime' => 'audio/mpeg',
    ]);

    expect(TranscribeTool::$effects)->toBe([['text' => 'the quarterly report is ready', 'audio_seconds' => 3.5]])
        ->and($response->output)->toBe('workflow-complete');
    Http::assertSentCount(2);
});

it('indexes into a native vector store inside a workflow and confirms retrieval by stable id', function () {
    Stores::fake();

    $response = runCapabilityWorkflow(new IndexAndSearchTool, 'IndexAndSearchTool', [
        'document' => 'Swarm proves native capabilities inside workflows.',
    ]);

    expect(IndexAndSearchTool::$effects)->toHaveCount(1);
    $indexed = IndexAndSearchTool::$effects[0];

    // Stable references, deterministic and re-derivable — not payloads.
    expect($indexed['file_id'])->toBe(Files::fakeId(IndexAndSearchTool::FILE_NAME))
        ->and($indexed['store_id'])->toBe(Stores::fakeId(IndexAndSearchTool::STORE_NAME))
        ->and($indexed['fetched_id'])->toBe($indexed['store_id'])
        ->and($indexed['ready'])->toBeTrue()
        ->and($response->output)->toBe('workflow-complete');
    Http::assertSentCount(2);
});

it('carries native capabilities across a genuine multi-step sequential workflow', function () {
    // Step 1 embeds; step 2 reranks. Two real workflow steps, each running a
    // native modality in its own tool loop, with the step outputs chained.
    Embeddings::fake([
        new EmbeddingsResponse([[0.5, 0.5]], new Usage(9, 0), new Meta('openai', 'text-embedding-3-small')),
    ]);
    Reranking::fake([
        new RerankingResponse([
            new RankedDocument(2, RerankTool::CANDIDATES[2], 0.88),
        ], new RerankingUsage(4), new Meta('cohere', 'rerank-v3.5')),
    ]);

    $requests = 0;
    Http::fake(function (Request $request) use (&$requests) {
        $requests++;

        return match ($requests) {
            1 => Http::response(NativeCapabilityWire::toolCall('EmbedTool', ['text' => 'task'])),
            2 => Http::response(NativeCapabilityWire::message('embedded')),
            3 => Http::response(NativeCapabilityWire::toolCall('RerankTool', ['query' => 'embedded'])),
            default => Http::response(NativeCapabilityWire::message('retrieved')),
        };
    });

    $response = app(SwarmRunner::class)->sequential([
        new CapabilityAgent([new EmbedTool], 'Intake worker.'),
        new CapabilityAgent([new RerankTool], 'Retrieval worker.'),
    ])->prompt('task');

    expect($response->steps)->toHaveCount(2)
        ->and(EmbedTool::$effects)->toBe([['dimensions' => 2, 'input_tokens' => 9]])
        ->and(RerankTool::$effects[0]['top_index'])->toBe(2)
        // Sequential contract: step 2's input is step 1's output.
        ->and($response->steps[1]->input)->toBe($response->steps[0]->output)
        ->and($response->output)->toBe('retrieved');
    Http::assertSentCount(4);
});

it('exposes every proven native capability family as a first-class laravel/ai primitive', function () {
    // Inventory guard (AC1): the six families this component proves exist natively.
    expect(class_exists(Laravel\Ai\Classification::class))->toBeTrue()
        ->and(class_exists(Laravel\Ai\Image::class))->toBeTrue()
        ->and(class_exists(Laravel\Ai\Audio::class))->toBeTrue()
        ->and(class_exists(Laravel\Ai\Transcription::class))->toBeTrue()
        ->and(class_exists(Laravel\Ai\Files::class))->toBeTrue()
        ->and(class_exists(Laravel\Ai\Stores::class))->toBeTrue()
        ->and(class_exists(Laravel\Ai\Embeddings::class))->toBeTrue()
        ->and(class_exists(Laravel\Ai\Reranking::class))->toBeTrue();
});
