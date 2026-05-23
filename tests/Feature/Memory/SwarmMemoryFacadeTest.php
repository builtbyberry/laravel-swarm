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

test('Swarm::memory() returns the bound SwarmMemory instance', function () {
    $memory = Swarm::memory();

    expect($memory)->toBeInstanceOf(SwarmMemory::class);
    expect($memory)->toBe($this->app->make(SwarmMemory::class));
});

test('Swarm::memory() round-trips a value through the cache-backed default store', function () {
    Swarm::memory()->put(MemoryScope::Run, 'run-facade', 'greeting', 'hello world');

    expect(Swarm::memory()->get(MemoryScope::Run, 'run-facade', 'greeting'))->toBe('hello world');
});
