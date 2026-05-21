<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Examples\StarterExampleRenderer;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->namespace = StarterExampleRenderer::render('parallel-research-fanout');
});

test('parallel-research-fanout starter runs all three scouts on the original task', function () {
    $swarmClass = $this->namespace.'\\Ai\\Swarms\\ParallelResearchFanout\\ResearchFanout';

    expect(class_exists($swarmClass))->toBeTrue();

    $task = 'AI agent orchestration for Laravel apps';
    $response = $swarmClass::make()->prompt($task);

    expect($response)->toBeInstanceOf(SwarmResponse::class)
        ->and($response->steps)->toHaveCount(3);

    // Parallel contract: every step receives the original input.
    foreach ($response->steps as $step) {
        expect($step->input)->toBe($task);
    }

    expect((string) $response)
        ->toContain('[MarketScout]')
        ->toContain('[CompetitorScout]')
        ->toContain('[CustomerScout]');
});

test('parallel-research-fanout runner command prints one block per scout', function () {
    $commandClass = $this->namespace.'\\Console\\Commands\\SwarmExampleResearchFanoutCommand';

    expect(class_exists($commandClass))->toBeTrue();

    Artisan::registerCommand(new $commandClass);

    $exit = Artisan::call('swarm:example:research-fanout', ['topic' => 'Laravel native AI tooling']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)
        ->toContain('MarketScout')
        ->toContain('CompetitorScout')
        ->toContain('CustomerScout');
});
