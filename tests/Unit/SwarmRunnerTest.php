<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithExecutionAttribute;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithMaxAgentStepsAttribute;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithoutTopologyAttribute;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithParallelTopologyAttribute;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithTimeoutAttribute;

test('topology falls back to configuration when no attribute is present', function () {
    config(['swarm.topology' => Topology::Parallel->value]);

    $runner = app(SwarmRunner::class);

    expect($runner->resolveTopology(new SwarmWithoutTopologyAttribute))->toBe(Topology::Parallel);
});

test('topology attribute overrides configuration', function () {
    config(['swarm.topology' => Topology::Sequential->value]);

    $runner = app(SwarmRunner::class);

    expect($runner->resolveTopology(new SwarmWithParallelTopologyAttribute))->toBe(Topology::Parallel);
});

test('resolve timeout reads attribute when present', function () {
    config(['swarm.timeout' => 900]);

    $runner = app(SwarmRunner::class);

    expect($runner->resolveTimeoutSeconds(new SwarmWithTimeoutAttribute))->toBe(42);
});

test('resolve timeout falls back to configuration', function () {
    config(['swarm.timeout' => 120]);

    $runner = app(SwarmRunner::class);

    expect($runner->resolveTimeoutSeconds(new SwarmWithoutTopologyAttribute))->toBe(120);
});

test('resolve max agent steps reads attribute when present', function () {
    config(['swarm.max_agent_steps' => 99]);

    $runner = app(SwarmRunner::class);

    expect($runner->resolveMaxAgentExecutions(new SwarmWithMaxAgentStepsAttribute))->toBe(4);
});

test('resolve max agent steps falls back to configuration', function () {
    config(['swarm.max_agent_steps' => 7]);

    $runner = app(SwarmRunner::class);

    expect($runner->resolveMaxAgentExecutions(new SwarmWithoutTopologyAttribute))->toBe(7);
});

test('resolve execution mode reads attribute when present', function () {
    config(['swarm.execution.mode' => ExecutionMode::Sync->value]);

    $runner = app(SwarmRunner::class);

    expect($runner->resolveExecutionMode(new SwarmWithExecutionAttribute))->toBe(ExecutionMode::Queued);
});

test('resolve execution mode falls back to configuration', function () {
    config(['swarm.execution.mode' => ExecutionMode::Mixed->value]);

    $runner = app(SwarmRunner::class);

    expect($runner->resolveExecutionMode(new SwarmWithoutTopologyAttribute))->toBe(ExecutionMode::Mixed);
});
