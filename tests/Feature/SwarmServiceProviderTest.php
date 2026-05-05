<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmCancelCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmHealthCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmHistoryCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmPauseCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmPruneCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmRecoverCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmResumeCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmStatusCommand;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\LaravelSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\CacheArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\CacheContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheStreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseStreamEventStore;
use BuiltByBerry\LaravelSwarm\Runners\SequentialStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Support\Facades\Artisan;

test('the swarm runner resolves from the container', function () {
    expect(app(SwarmRunner::class))->toBeInstanceOf(SwarmRunner::class);
    expect(app(SequentialStreamRunner::class))->toBeInstanceOf(SequentialStreamRunner::class);
    expect(app(SwarmHistory::class))->toBeInstanceOf(SwarmHistory::class);
    expect(app(ContextStore::class))->toBeInstanceOf(ContextStore::class);
    expect(app(ArtifactRepository::class))->toBeInstanceOf(ArtifactRepository::class);
    expect(app(RunHistoryStore::class))->toBeInstanceOf(RunHistoryStore::class);
    expect(app(StreamEventStore::class))->toBeInstanceOf(StreamEventStore::class);
    expect(app(DurableRunStore::class))->toBeInstanceOf(DurableRunStore::class);
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
    expect($commands['swarm:health'])->toBeInstanceOf(SwarmHealthCommand::class);
    expect($commands['swarm:prune'])->toBeInstanceOf(SwarmPruneCommand::class);
    expect($commands['swarm:status'])->toBeInstanceOf(SwarmStatusCommand::class);
    expect($commands['swarm:history'])->toBeInstanceOf(SwarmHistoryCommand::class);
    expect($commands['swarm:pause'])->toBeInstanceOf(SwarmPauseCommand::class);
    expect($commands['swarm:resume'])->toBeInstanceOf(SwarmResumeCommand::class);
    expect($commands['swarm:cancel'])->toBeInstanceOf(SwarmCancelCommand::class);
    expect($commands['swarm:recover'])->toBeInstanceOf(SwarmRecoverCommand::class);
});

test('package migrations are published through laravel migration publishing', function () {
    $paths = SwarmServiceProvider::pathsToPublish(SwarmServiceProvider::class, 'swarm-migrations');
    $migrationPath = realpath(__DIR__.'/../../database/migrations');

    expect(array_map(realpath(...), array_keys($paths)))->toContain($migrationPath);
    expect(array_values($paths))->toContain(database_path('migrations'));
});

test('the container resolves cache persistence stores by default', function () {
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(StreamEventStore::class);

    expect(app(ContextStore::class))->toBeInstanceOf(CacheContextStore::class);
    expect(app(ArtifactRepository::class))->toBeInstanceOf(CacheArtifactRepository::class);
    expect(app(RunHistoryStore::class))->toBeInstanceOf(CacheRunHistoryStore::class);
    expect(app(StreamEventStore::class))->toBeInstanceOf(CacheStreamEventStore::class);
});

test('the container resolves database persistence stores from the global driver', function () {
    config()->set('swarm.persistence.driver', 'database');
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(StreamEventStore::class);

    expect(app(ContextStore::class))->toBeInstanceOf(DatabaseContextStore::class);
    expect(app(ArtifactRepository::class))->toBeInstanceOf(DatabaseArtifactRepository::class);
    expect(app(RunHistoryStore::class))->toBeInstanceOf(DatabaseRunHistoryStore::class);
    expect(app(StreamEventStore::class))->toBeInstanceOf(DatabaseStreamEventStore::class);
});

test('blank per-store persistence drivers fall back to the global driver', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.context.driver', '');
    config()->set('swarm.artifacts.driver', '');
    config()->set('swarm.history.driver', '');
    config()->set('swarm.streaming.replay.driver', '');
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(StreamEventStore::class);

    expect(app(ContextStore::class))->toBeInstanceOf(DatabaseContextStore::class);
    expect(app(ArtifactRepository::class))->toBeInstanceOf(DatabaseArtifactRepository::class);
    expect(app(RunHistoryStore::class))->toBeInstanceOf(DatabaseRunHistoryStore::class);
    expect(app(StreamEventStore::class))->toBeInstanceOf(DatabaseStreamEventStore::class);
});

test('the container resolves the durable run store', function () {
    expect(app(DurableRunStore::class))->toBeInstanceOf(DatabaseDurableRunStore::class);
});

test('per-store persistence driver overrides the global driver', function () {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.context.driver', 'database');
    config()->set('swarm.artifacts.driver', 'database');
    config()->set('swarm.history.driver', 'database');
    config()->set('swarm.streaming.replay.driver', 'database');
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(StreamEventStore::class);

    expect(app(ContextStore::class))->toBeInstanceOf(DatabaseContextStore::class);
    expect(app(ArtifactRepository::class))->toBeInstanceOf(DatabaseArtifactRepository::class);
    expect(app(RunHistoryStore::class))->toBeInstanceOf(DatabaseRunHistoryStore::class);
    expect(app(StreamEventStore::class))->toBeInstanceOf(DatabaseStreamEventStore::class);
});

test('invalid global persistence driver fails clearly', function () {
    config()->set('swarm.persistence.driver', 'databse');
    app()->forgetInstance(ContextStore::class);

    expect(fn () => app(ContextStore::class))
        ->toThrow(InvalidArgumentException::class, 'Laravel Swarm: invalid persistence driver [databse]. Supported drivers: cache, database.');
});

test('invalid per-store persistence driver fails clearly', function () {
    config()->set('swarm.context.driver', 'redis');
    app()->forgetInstance(ContextStore::class);

    expect(fn () => app(ContextStore::class))
        ->toThrow(InvalidArgumentException::class, 'Laravel Swarm: invalid persistence driver [redis]. Supported drivers: cache, database.');
});

test('package migrations are autoloaded by default', function () {
    $migrationPath = realpath(__DIR__.'/../../database/migrations');

    expect(array_map('realpath', app('migrator')->paths()))->toContain($migrationPath);
});

test('LaravelSwarm::ignoreMigrations() skips migration autoloading', function () {
    LaravelSwarm::ignoreMigrations();

    $pathsBefore = app('migrator')->paths();

    (new SwarmServiceProvider($this->app))->boot();

    expect(app('migrator')->paths())->toBe($pathsBefore);
})->after(fn () => LaravelSwarm::$runsMigrations = true);

test('swarm-migrations publish tag resolves even when ignoreMigrations() was called', function () {
    LaravelSwarm::ignoreMigrations();

    $paths = SwarmServiceProvider::pathsToPublish(SwarmServiceProvider::class, 'swarm-migrations');
    $migrationPath = realpath(__DIR__.'/../../database/migrations');

    expect(array_map('realpath', array_keys($paths)))->toContain($migrationPath);
})->after(fn () => LaravelSwarm::$runsMigrations = true);
