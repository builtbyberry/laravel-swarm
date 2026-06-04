<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRestrictivePropagationSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeWideViewPropagationSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\RestrictivePropagationPolicy;
use BuiltByBerry\LaravelSwarm\Tools\Recall;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Recall reads Swarm memory mid-prompt through the active swarm's propagation
 * policy, so it can only surface what the policy permits the agent to see, and
 * degrades to a graceful message outside a swarm run.
 */
afterEach(function () {
    ActiveRunContext::flush();
});

function recall(array $arguments): string
{
    return app(Recall::class)->handle(new Request($arguments));
}

function enterRecallRun(string $runId, string $swarmClass): void
{
    ActiveRunContext::enter($runId, $swarmClass, RunContext::fake(['run_id' => $runId, 'input' => 'go']));
}

test('it implements the Laravel AI Tool contract', function () {
    expect(new Recall)->toBeInstanceOf(Tool::class);
});

test('its schema describes key, prefix, and scope', function () {
    $schema = (new Recall)->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['key', 'prefix', 'scope']);
    expect($schema['key'])->toBeInstanceOf(Type::class);
    expect($schema['prefix'])->toBeInstanceOf(Type::class);
    expect($schema['scope'])->toBeInstanceOf(Type::class);
});

test('it reads a single key from run scope by default', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'launch plan');
    enterRecallRun('run-1', FakeSequentialSwarm::class);

    expect(recall(['key' => 'topic']))->toBe('topic: launch plan');
});

test('it reports a miss for an absent key', function () {
    enterRecallRun('run-1', FakeSequentialSwarm::class);

    expect(recall(['key' => 'nope']))->toBe('No memory found for key [nope].');
});

test('it lists every entry in a scope when no key or prefix is given', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'launch plan');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'count', 42);
    enterRecallRun('run-1', FakeSequentialSwarm::class);

    $output = recall([]);

    expect($output)->toContain('topic: launch plan');
    expect($output)->toContain('count: 42');
});

test('it filters by prefix', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'finding.a', 'alpha');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'finding.b', 'beta');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'other', 'gamma');
    enterRecallRun('run-1', FakeSequentialSwarm::class);

    $output = recall(['prefix' => 'finding.']);

    expect($output)->toContain('finding.a: alpha');
    expect($output)->toContain('finding.b: beta');
    expect($output)->not->toContain('other');
});

test('it serializes bool, int, and array values', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'flag', true);
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'data', ['a' => 1]);
    enterRecallRun('run-1', FakeSequentialSwarm::class);

    expect(recall(['key' => 'flag']))->toBe('flag: true');
    expect(recall(['key' => 'data']))->toBe('data: {"a":1}');
});

test('it respects the propagation policy and never leaks a withheld scope', function () {
    // Restrictive policy gathers Run + Swarm but presents only the allow-listed key.
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', RestrictivePropagationPolicy::ALLOWED_KEY, 'visible');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'secret', 'hidden');
    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeRestrictivePropagationSwarm::class, 'swarm-secret', 'hidden');
    enterRecallRun('run-1', FakeRestrictivePropagationSwarm::class);

    expect(recall(['key' => RestrictivePropagationPolicy::ALLOWED_KEY]))->toBe(RestrictivePropagationPolicy::ALLOWED_KEY.': visible');
    expect(recall(['key' => 'secret']))->toBe('No memory found for key [secret].');
    expect(recall(['scope' => 'swarm']))->toBe('No memory found.');
});

test('it surfaces a wider scope when the policy permits it', function () {
    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeWideViewPropagationSwarm::class, 'shared', 'team-state');
    enterRecallRun('run-1', FakeWideViewPropagationSwarm::class);

    expect(recall(['key' => 'shared', 'scope' => 'swarm']))->toBe('shared: team-state');
});

test('it degrades gracefully outside a swarm run', function () {
    expect(recall(['key' => 'topic']))->toBe('Memory is not available outside an active swarm run.');
});

test('it rejects an unknown scope name', function () {
    enterRecallRun('run-1', FakeSequentialSwarm::class);

    expect(recall(['scope' => 'bogus']))->toBe('Unknown memory scope. Use one of: run, swarm, agent, conversation.');
});
