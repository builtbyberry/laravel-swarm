<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Facades\Swarm;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use Illuminate\Contracts\Cache\Factory;

beforeEach(function () {
    /** @var Factory $cacheFactory */
    $cacheFactory = $this->app->make('cache');
    $cacheFactory->store('array')->flush();
});

test('the SwarmMemory contract is bound to DefaultSwarmMemory', function () {
    expect($this->app->make(SwarmMemory::class))->toBeInstanceOf(DefaultSwarmMemory::class);
});

test('Swarm::memory() resolves the bound SwarmMemory through the container', function () {
    $memory = Swarm::memory();

    expect($memory)->toBeInstanceOf(SwarmMemory::class);
    // The contract resolves through the container's SwarmMemory binding, which is
    // intentionally NOT a singleton: DefaultSwarmMemory is a stateless coordinator
    // rebuilt around the current MemoryStore on each resolution (so a rebound store
    // is honoured and the per-frame replay override can take precedence). Assert
    // the resolution path and concrete type, not object identity.
    expect($memory)->toBeInstanceOf(DefaultSwarmMemory::class);
    expect($this->app->make(SwarmMemory::class))->toBeInstanceOf(DefaultSwarmMemory::class);
});

test('Swarm::memory() round-trips a value through the cache-backed default store', function () {
    Swarm::memory()->put(MemoryScope::Run, 'run-facade', 'greeting', 'hello world');

    expect(Swarm::memory()->get(MemoryScope::Run, 'run-facade', 'greeting'))->toBe('hello world');
});
