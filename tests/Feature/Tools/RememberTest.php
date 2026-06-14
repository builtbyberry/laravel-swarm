<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryToolAgent;
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

test('its schema requires key and value and offers the four scopes', function () {
    $schema = (new Remember)->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['key', 'value', 'scope']);

    // `required` is a protected flag the serializer collapses into the parent
    // object, so read it directly to prove the contract the tool name promises.
    $required = fn (Type $type): bool => (new ReflectionProperty(Type::class, 'required'))->getValue($type) === true;

    expect($required($schema['key']))->toBeTrue();
    expect($required($schema['value']))->toBeTrue();
    expect($required($schema['scope']))->toBeFalse();

    expect($schema['scope']->toArray()['enum'] ?? null)
        ->toBe(array_map(static fn (MemoryScope $scope): string => $scope->value, MemoryScope::cases()));
});

test('it writes to run scope by default and confirms', function () {
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    $result = remember(['key' => 'topic', 'value' => 'launch plan']);

    expect($result)->toBe('Stored [topic] in run memory.');
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'run-1', 'topic'))->toBe('launch plan');
});

test('it stores a null value when value is omitted', function () {
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    $result = remember(['key' => 'noted']);

    expect($result)->toBe('Stored [noted] in run memory.');

    // A row is written with a null value — distinct from "nothing was stored".
    $entry = app(SwarmMemory::class)->entry(MemoryScope::Run, 'run-1', 'noted');
    expect($entry)->not->toBeNull()
        ->and($entry->value)->toBeNull();
});

test('it attributes tool writes with a tool:remember origin in metadata', function () {
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    remember(['key' => 'topic', 'value' => 'launch plan']);

    $entry = app(SwarmMemory::class)->entry(MemoryScope::Run, 'run-1', 'topic');

    expect($entry->metadata)->toHaveKey('origin')
        ->and($entry->metadata['origin'])->toBe('tool:remember');
});

test('it records the bound agent class in metadata when agent-bound', function () {
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    // A tool bound to a specific agent (as make:memory-tool --scope=agent
    // scaffolds) attributes its writes to that agent class, so MemoryWritten
    // audit listeners can tell which agent persisted the entry.
    $tool = new class extends Remember
    {
        protected function agent(): ?Agent
        {
            return new MemoryToolAgent;
        }
    };

    $tool->handle(new Request(['key' => 'topic', 'value' => 'launch plan']));

    $entry = app(SwarmMemory::class)->entry(MemoryScope::Run, 'run-1', 'topic');

    expect($entry->metadata)->toHaveKey('agent')
        ->and($entry->metadata['agent'])->toBe(MemoryToolAgent::class);
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

test('it reports an unaddressable scope distinctly while in a run', function () {
    enterRememberRun('run-1', FakeSequentialSwarm::class);

    // The conversation scope is unaddressable because this run carries no
    // conversation id, but the run is active — so the message must not claim
    // there is no run.
    expect(remember(['key' => 'topic', 'value' => 'x', 'scope' => 'conversation']))
        ->toBe('The [conversation] scope is not addressable in this run.');
});

test('it writes to conversation scope when the run is bound to a conversation', function () {
    ActiveRunContext::enter(
        'run-1',
        FakeSequentialSwarm::class,
        RunContext::fake(['run_id' => 'run-1', 'input' => 'go', 'conversation_id' => 'conv-5']),
    );

    $result = remember(['key' => 'topic', 'value' => 'launch plan', 'scope' => 'conversation']);

    expect($result)->toBe('Stored [topic] in conversation memory.');
    expect(app(SwarmMemory::class)->get(MemoryScope::Conversation, 'conv-5', 'topic'))->toBe('launch plan');
});
