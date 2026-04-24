<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmHistoryCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmPruneCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmStatusCommand;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\CacheContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use Illuminate\Support\Facades\Artisan;

test('the swarm runner resolves from the container', function () {
    expect(app(SwarmRunner::class))->toBeInstanceOf(SwarmRunner::class);
    expect(app(SwarmHistory::class))->toBeInstanceOf(SwarmHistory::class);
    expect(app(ContextStore::class))->toBeInstanceOf(ContextStore::class);
    expect(app(ArtifactRepository::class))->toBeInstanceOf(ArtifactRepository::class);
    expect(app(RunHistoryStore::class))->toBeInstanceOf(RunHistoryStore::class);
});

test('the swarm configuration is merged', function () {
    expect(config('swarm.topology'))->toBeString();
    expect(config('swarm.timeout'))->toBeInt();
    expect(config('swarm.max_agent_steps'))->toBeInt();
    expect(config('swarm.persistence.driver'))->toBeString();
});

test('the make swarm command is registered', function () {
    $commands = Artisan::all();

    expect(array_key_exists('make:swarm', $commands))->toBeTrue();
    expect($commands['make:swarm'])->toBeInstanceOf(MakeSwarmCommand::class);
    expect($commands['swarm:prune'])->toBeInstanceOf(SwarmPruneCommand::class);
    expect($commands['swarm:status'])->toBeInstanceOf(SwarmStatusCommand::class);
    expect($commands['swarm:history'])->toBeInstanceOf(SwarmHistoryCommand::class);
});

test('the container resolves cache persistence stores by default', function () {
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);

    expect(app(ContextStore::class))->toBeInstanceOf(CacheContextStore::class);
    expect(app(ArtifactRepository::class))->toBeInstanceOf(CacheArtifactRepository::class);
    expect(app(RunHistoryStore::class))->toBeInstanceOf(CacheRunHistoryStore::class);
});

test('the container resolves database persistence stores from the global driver', function () {
    config()->set('swarm.persistence.driver', 'database');
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);

    expect(app(ContextStore::class))->toBeInstanceOf(DatabaseContextStore::class);
    expect(app(ArtifactRepository::class))->toBeInstanceOf(DatabaseArtifactRepository::class);
    expect(app(RunHistoryStore::class))->toBeInstanceOf(DatabaseRunHistoryStore::class);
});

test('per-store persistence driver overrides the global driver', function () {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.context.driver', 'database');
    config()->set('swarm.artifacts.driver', 'database');
    config()->set('swarm.history.driver', 'database');
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);

    expect(app(ContextStore::class))->toBeInstanceOf(DatabaseContextStore::class);
    expect(app(ArtifactRepository::class))->toBeInstanceOf(DatabaseArtifactRepository::class);
    expect(app(RunHistoryStore::class))->toBeInstanceOf(DatabaseRunHistoryStore::class);
});
