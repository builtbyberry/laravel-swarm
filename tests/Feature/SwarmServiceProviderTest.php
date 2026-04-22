<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmCommand;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\ExecutionPolicyResolver;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use Illuminate\Support\Facades\Artisan;

test('the swarm runner resolves from the container', function () {
    expect(app(SwarmRunner::class))->toBeInstanceOf(SwarmRunner::class);
    expect(app(ContextStore::class))->toBeInstanceOf(ContextStore::class);
    expect(app(ArtifactRepository::class))->toBeInstanceOf(ArtifactRepository::class);
    expect(app(RunHistoryStore::class))->toBeInstanceOf(RunHistoryStore::class);
    expect(app(ExecutionPolicyResolver::class))->toBeInstanceOf(ExecutionPolicyResolver::class);
});

test('the swarm configuration is merged', function () {
    expect(config('swarm.topology'))->toBeString();
    expect(config('swarm.timeout'))->toBeInt();
    expect(config('swarm.max_agent_steps'))->toBeInt();
    expect(config('swarm.context.driver'))->toBeString();
    expect(config('swarm.execution.mode'))->toBeString();
    expect(config('swarm.artifacts.driver'))->toBeString();
    expect(config('swarm.history.driver'))->toBeString();
});

test('the make swarm command is registered', function () {
    $commands = Artisan::all();

    expect(array_key_exists('make:swarm', $commands))->toBeTrue();
    expect($commands['make:swarm'])->toBeInstanceOf(MakeSwarmCommand::class);
});
