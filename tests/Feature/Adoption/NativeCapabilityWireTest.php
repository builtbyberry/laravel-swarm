<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\CapabilityAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\EmbedTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\NativeCapabilityWire;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\RerankTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Image;
use Laravel\Ai\Reranking;
use RuntimeException;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('ai.providers.anthropic.key', 'test-key');
    config()->set('ai.conversations.generate_title', false);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Http::preventStrayRequests();
    EmbedTool::$effects = [];
    RerankTool::$effects = [];
});

it('forms the real native embeddings wire request and threads the parsed result through the workflow', function () {
    // No modality fake here: the REAL embeddings gateway runs, so its outbound
    // request is a controlled native wire fixture and an assertable protocol boundary.
    $responsesTurns = 0;
    $embeddingsRequests = 0;
    Http::fake(function (Request $request) use (&$responsesTurns, &$embeddingsRequests) {
        if (str_contains($request->url(), '/embeddings')) {
            $embeddingsRequests++;
            expect($request['model'])->toBe('text-embedding-3-small')
                ->and($request['input'])->toBe(['retrieve me'])
                ->and($request['dimensions'])->toBeInt();

            return Http::response([
                'data' => [['embedding' => [0.11, 0.22, 0.33, 0.44, 0.55]]],
                'usage' => ['prompt_tokens' => 13],
            ]);
        }

        $responsesTurns++;

        return Http::response($responsesTurns === 1
            ? NativeCapabilityWire::toolCall('EmbedTool', ['text' => 'retrieve me'])
            : NativeCapabilityWire::message());
    });

    config()->set('ai.default_for_embeddings', 'openai');
    $response = app(SwarmRunner::class)->agent(new CapabilityAgent([new EmbedTool]))->prompt('task');

    expect($embeddingsRequests)->toBe(1)
        ->and(EmbedTool::$effects)->toBe([['dimensions' => 5, 'input_tokens' => 13]])
        // Outer text usage stays the wire's 4/6 — the 13 embedding tokens are the
        // nested modality's, surfaced at the tool layer, not folded into the text total.
        ->and($response->usage['input_tokens'])->toBe(4)
        ->and($response->usage['output_tokens'])->toBe(6);
});

it('propagates a native modality failure out of the workflow instead of swallowing it', function () {
    Reranking::fake(function () {
        throw new RuntimeException('reranking provider unavailable');
    });

    $responsesTurns = 0;
    Http::fake(function (Request $request) use (&$responsesTurns) {
        $responsesTurns++;

        return Http::response($responsesTurns === 1
            ? NativeCapabilityWire::toolCall('RerankTool', ['query' => 'boom'])
            : NativeCapabilityWire::message());
    });

    expect(fn () => app(SwarmRunner::class)->agent(new CapabilityAgent([new RerankTool]))->prompt('task'))
        ->toThrow(RuntimeException::class, 'reranking provider unavailable');
    expect(RerankTool::$effects)->toBe([]);
});

it('preserves a native provider capability limitation explicitly', function () {
    // Executable evidence for the unsupported-combinations matrix: a provider that
    // does not offer a modality fails loud rather than silently degrading.
    Http::preventStrayRequests();

    expect(fn () => Image::of('a diagram')->generate('anthropic'))
        ->toThrow(LogicException::class, 'does not support image generation.');
    Http::assertNothingSent();
});
