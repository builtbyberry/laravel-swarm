<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Runners\SwarmAttributeResolver;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithMaxAgentStepsAttribute;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithoutTopologyAttribute;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithParallelTopologyAttribute;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithTimeoutAttribute;

test('topology falls back to configuration when no attribute is present', function () {
    config(['swarm.topology' => Topology::Parallel->value]);

    $resolver = app(SwarmAttributeResolver::class);

    expect($resolver->resolveTopology(new SwarmWithoutTopologyAttribute))->toBe(Topology::Parallel);
});

test('topology attribute overrides configuration', function () {
    config(['swarm.topology' => Topology::Sequential->value]);

    $resolver = app(SwarmAttributeResolver::class);

    expect($resolver->resolveTopology(new SwarmWithParallelTopologyAttribute))->toBe(Topology::Parallel);
});

test('resolve timeout reads attribute when present', function () {
    config(['swarm.timeout' => 900]);

    $resolver = app(SwarmAttributeResolver::class);

    expect($resolver->resolveTimeoutSeconds(new SwarmWithTimeoutAttribute))->toBe(42);
});

test('resolve timeout falls back to configuration', function () {
    config(['swarm.timeout' => 120]);

    $resolver = app(SwarmAttributeResolver::class);

    expect($resolver->resolveTimeoutSeconds(new SwarmWithoutTopologyAttribute))->toBe(120);
});

test('resolve max agent steps reads attribute when present', function () {
    config(['swarm.max_agent_steps' => 99]);

    $resolver = app(SwarmAttributeResolver::class);

    expect($resolver->resolveMaxAgentExecutions(new SwarmWithMaxAgentStepsAttribute))->toBe(4);
});

test('resolve max agent steps falls back to configuration', function () {
    config(['swarm.max_agent_steps' => 7]);

    $resolver = app(SwarmAttributeResolver::class);

    expect($resolver->resolveMaxAgentExecutions(new SwarmWithoutTopologyAttribute))->toBe(7);
});
