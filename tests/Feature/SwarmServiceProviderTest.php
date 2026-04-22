<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmCommand;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use Illuminate\Support\Facades\Artisan;

test('the swarm runner resolves from the container', function () {
    expect(app(SwarmRunner::class))->toBeInstanceOf(SwarmRunner::class);
});

test('the swarm configuration is merged', function () {
    expect(config('swarm.topology'))->toBeString();
    expect(config('swarm.timeout'))->toBeInt();
    expect(config('swarm.max_agent_steps'))->toBeInt();
    expect(config('swarm.context.driver'))->toBeString();
});

test('the make swarm command is registered', function () {
    $commands = Artisan::all();

    expect(array_key_exists('make:swarm', $commands))->toBeTrue();
    expect($commands['make:swarm'])->toBeInstanceOf(MakeSwarmCommand::class);
});
