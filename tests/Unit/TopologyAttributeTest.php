<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Attributes\Topology as TopologyAttribute;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;

test('topology attribute resolves to the expected enum case', function () {
    $reflection = new ReflectionClass(FakeParallelSwarm::class);
    $attributes = $reflection->getAttributes(TopologyAttribute::class);

    expect($attributes)->not->toBeEmpty();

    $instance = $attributes[0]->newInstance();

    expect($instance->topology)->toBe(TopologyEnum::Parallel);
});
