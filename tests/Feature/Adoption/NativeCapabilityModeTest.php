<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\EmbedTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\NativeCapabilitySwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\NativeCapabilityWire;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.history.driver', 'database');
    config()->set('queue.default', 'sync');
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('ai.conversations.generate_title', false);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Http::preventStrayRequests();
    EmbedTool::$effects = [];
});

it('runs a native capability inside a queued workflow, not only prompt()', function () {
    // Beyond prompt(): the queued job re-resolves the swarm by class and runs the
    // agent tool loop in the worker, so the nested native modality really executes
    // on the background path.
    Embeddings::fake([
        new EmbeddingsResponse([[0.5, 0.5, 0.5, 0.5]], new Usage(9, 0), new Meta('openai', 'text-embedding-3-small')),
    ]);

    $turns = 0;
    Http::fake(function (Request $request) use (&$turns) {
        $turns++;

        return Http::response($turns === 1
            ? NativeCapabilityWire::toolCall('EmbedTool', ['text' => 'retrieve'])
            : NativeCapabilityWire::message());
    });

    app(SwarmRunner::class)->queue(new NativeCapabilitySwarm, 'task');

    // The embedding ran in the queued worker and produced its typed result.
    expect(EmbedTool::$effects)->toBe([['dimensions' => 4, 'input_tokens' => 9]]);
    Http::assertSentCount(2);
});
