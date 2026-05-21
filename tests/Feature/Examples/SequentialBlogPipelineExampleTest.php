<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Examples\StarterExampleRenderer;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->namespace = StarterExampleRenderer::render('sequential-blog-pipeline');
});

test('sequential-blog-pipeline starter runs end-to-end with the shipped ScriptedAgents', function () {
    $swarmClass = $this->namespace.'\\Ai\\Swarms\\SequentialBlogPipeline\\BlogPipeline';

    expect(class_exists($swarmClass))->toBeTrue();

    $response = $swarmClass::make()->prompt('Laravel queue visibility timeouts');

    expect($response)->toBeInstanceOf(SwarmResponse::class)
        ->and($response->steps)->toHaveCount(3)
        ->and((string) $response)
        ->toContain('Polished blog post')
        ->toContain('Laravel queue visibility timeouts');

    // Sequential contract: each step's input is the previous step's output.
    expect($response->steps[1]->input)->toBe($response->steps[0]->output)
        ->and($response->steps[2]->input)->toBe($response->steps[1]->output);
});

test('sequential-blog-pipeline runner command produces the polished output', function () {
    $commandClass = $this->namespace.'\\Console\\Commands\\SwarmExampleBlogPipelineCommand';

    expect(class_exists($commandClass))->toBeTrue();

    Artisan::registerCommand(new $commandClass);

    $exit = Artisan::call('swarm:example:blog-pipeline', ['topic' => 'Test driven swarms']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)
        ->toContain('Test driven swarms')
        ->toContain('Polished blog post');
});
