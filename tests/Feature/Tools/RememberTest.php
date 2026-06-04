<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\RedactingMemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingMemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Tools\Remember;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Remember writes Swarm memory mid-prompt through SwarmMemory::put — so the
 * capture policy redacts or drops at the write boundary exactly as for any
 * write — resolving the scope id from the active run, rejecting reserved keys,
 * and degrading gracefully outside a run.
 */
afterEach(function () {
    ActiveRunContext::flush();
});

function remember(array $arguments): string
{
    return app(Remember::class)->handle(new Request($arguments));
}

function enterRememberRun(string $runId, string $swarmClass): void
{
    ActiveRunContext::enter($runId, $swarmClass, RunContext::fake(['run_id' => $runId, 'input' => 'go']));
}

function bindMemoryCapturePolicy(MemoryCapturePolicy $policy): void
{
    app()->instance(MemoryCapturePolicy::class, $policy);
    app()->forgetInstance(MemoryStore::class);
    app()->forgetInstance(SwarmMemory::class);
}

test('it implements the Laravel AI Tool contract', function () {
    expect(new Remember)->toBeInstanceOf(Tool::class);
});

test('its schema requires key and value and offers scope', function () {
    $schema = (new Remember)->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['key', 'value', 'scope']);
    expect($schema['key'])->toBeInstanceOf(Type::class);
    expect($schema['value'])->toBeInstanceOf(Type::class);
    expect($schema['scope'])->toBeInstanceOf(Type::class);
});

test('it writes to run scope by default and confirms', function () {
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    $result = remember(['key' => 'topic', 'value' => 'launch plan']);

    expect($result)->toBe('Stored [topic] in run memory.');
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'run-1', 'topic'))->toBe('launch plan');
});

test('it writes to the swarm scope when asked, keyed by the active swarm class', function () {
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    remember(['key' => 'shared', 'value' => 'team-state', 'scope' => 'swarm']);

    expect(app(SwarmMemory::class)->get(MemoryScope::Swarm, FakeSequentialSwarm::class, 'shared'))->toBe('team-state');
    // It must not have leaked into the run scope.
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'run-1', 'shared'))->toBeNull();
});

test('it applies capture-policy redaction at the write boundary', function () {
    bindMemoryCapturePolicy(new RedactingMemoryCapturePolicy(['ssn']));
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    remember(['key' => 'ssn', 'value' => '123-45-6789']);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'run-1', 'ssn'))->toBe(SwarmCapture::REDACTED);
});

test('it honours a capture-policy skip decision', function () {
    bindMemoryCapturePolicy(new SkippingMemoryCapturePolicy(['secret']));
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    remember(['key' => 'secret', 'value' => 'do-not-store']);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'run-1', 'secret'))->toBeNull();
});

test('it rejects reserved swarm: keys', function () {
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    $result = remember(['key' => 'swarm:step.0.output', 'value' => 'tampered']);

    expect($result)->toContain('reserved');
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'run-1', 'swarm:step.0.output'))->toBeNull();
});

test('it rejects an empty key', function () {
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    expect(remember(['key' => '', 'value' => 'x']))->toBe('A memory key is required.');
});

test('it rejects an unknown scope', function () {
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    expect(remember(['key' => 'k', 'value' => 'v', 'scope' => 'bogus']))
        ->toBe('Unknown memory scope. Use one of: run, swarm, agent, conversation.');
});

test('it degrades gracefully outside a swarm run', function () {
    expect(remember(['key' => 'topic', 'value' => 'x']))
        ->toBe('Memory is not available outside an active swarm run.');
});
