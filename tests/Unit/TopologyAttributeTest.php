<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps;
use BuiltByBerry\LaravelSwarm\Attributes\Timeout;
use BuiltByBerry\LaravelSwarm\Attributes\Topology as TopologyAttribute;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;

test('topology attribute resolves to the expected enum case', function () {
    $reflection = new ReflectionClass(FakeParallelSwarm::class);
    $attributes = $reflection->getAttributes(TopologyAttribute::class);

    expect($attributes)->not->toBeEmpty();

    $instance = $attributes[0]->newInstance();

    expect($instance->topology)->toBe(TopologyEnum::Parallel);
});

test('timeout attribute requires a positive integer', function (int $seconds) {
    expect(fn () => new Timeout($seconds))
        ->toThrow(SwarmException::class, 'Swarm timeout must be a positive integer.');
})->with([0, -1]);

test('max agent steps attribute requires a positive integer', function (int $steps) {
    expect(fn () => new MaxAgentSteps($steps))
        ->toThrow(SwarmException::class, 'Swarm max agent steps must be a positive integer.');
})->with([0, -1]);
