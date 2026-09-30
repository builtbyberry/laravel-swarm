<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Examples\StarterExampleRenderer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // The starter is fully offline (ScriptedAgent) — no provider must be touched.
    Http::preventStrayRequests();
    $this->namespace = StarterExampleRenderer::render('native-media-retrieval');
});

test('native-media-retrieval starter answers a question with native retrieval, end-to-end offline', function () {
    $swarmClass = $this->namespace.'\\Ai\\Swarms\\NativeMediaRetrieval\\MediaRetrievalPipeline';

    expect(class_exists($swarmClass))->toBeTrue();

    $response = $swarmClass::make()->prompt('What execution modes do Swarm workflows run in?');

    expect($response)->toBeInstanceOf(SwarmResponse::class)
        ->and($response->steps)->toHaveCount(3)
        // The final answer is grounded in a retrieved passage.
        ->and($response->output)->toStartWith('Based on the knowledge base:')
        ->and($response->output)->toContain('execution modes');

    // Sequential contract: each agent's input is the prior agent's output.
    expect($response->steps[1]->input)->toBe($response->steps[0]->output)
        ->and($response->steps[2]->input)->toBe($response->steps[1]->output);

    // The retrieval step returned ranked passages as structured JSON.
    $retrieved = json_decode($response->steps[1]->output, true, flags: JSON_THROW_ON_ERROR);
    expect($retrieved)->toHaveKey('passages')
        ->and($retrieved['passages'])->toBeArray()->not->toBeEmpty();

    Http::assertNothingSent();
});

test('native-media-retrieval runner command reports a grounded answer', function () {
    $commandClass = $this->namespace.'\\Console\\Commands\\SwarmExampleMediaRetrievalCommand';

    expect(class_exists($commandClass))->toBeTrue();

    Artisan::registerCommand(new $commandClass);

    $exit = Artisan::call('swarm:example:media-retrieval', [
        'question' => 'What is native retrieval in Swarm?',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)
        ->toContain('Grounded answer')
        ->toContain('Based on the knowledge base:');

    Http::assertNothingSent();
});
