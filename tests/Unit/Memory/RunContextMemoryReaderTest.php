<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\RunContextMemoryReader;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeWideViewPropagationSwarm;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;

/**
 * RunContextMemoryReader turns the active run's propagation-policy memory view
 * into laravel/ai Messages. It reads through AgentVisibleMemoryView (so policy
 * filtering and canonical order apply) and is a no-op when no run is active.
 */
afterEach(function () {
    ActiveRunContext::exit();
});

function enterReaderRun(string $runId, string $swarmClass): void
{
    ActiveRunContext::enter($runId, $swarmClass, RunContext::fake(['run_id' => $runId, 'input' => 'go']));
}

/**
 * @return array<int, string|null>
 */
function renderedContents(?MessageRole $role = null): array
{
    $reader = app(RunContextMemoryReader::class);
    $messages = $role === null ? $reader->messages(null) : $reader->messages(null, $role);

    return array_map(static fn (Message $message): ?string => $message->content, $messages);
}

test('returns an empty list when no run is active', function () {
    expect(app(RunContextMemoryReader::class)->messages(null))->toBe([]);
});

test('renders Run-scoped entries as key-prefixed messages', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'launch plan');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'count', 42);

    enterReaderRun('run-1', FakeSequentialSwarm::class);

    $contents = renderedContents();

    expect($contents)->toContain('topic: launch plan');
    expect($contents)->toContain('count: 42');
    expect($contents)->toHaveCount(2);
});

test('serializes bool and array values and skips nulls', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-2', 'flag', true);
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-2', 'data', ['a' => 1]);
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-2', 'empty', null);

    enterReaderRun('run-2', FakeSequentialSwarm::class);

    $contents = renderedContents();

    expect($contents)->toContain('flag: true');
    expect($contents)->toContain('data: {"a":1}');
    // The null-valued entry produces no message.
    expect($contents)->toHaveCount(2);
});

test('assigns the requested role to every rendered message', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-3', 'topic', 'hello');

    enterReaderRun('run-3', FakeSequentialSwarm::class);

    $messages = app(RunContextMemoryReader::class)->messages(null, MessageRole::User);

    expect($messages[0])->toBeInstanceOf(Message::class);
    expect($messages[0]->role)->toBe(MessageRole::User);
});

test('honours the default policy: non-Run scopes are dropped', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-4', 'run-note', 'visible');
    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeSequentialSwarm::class, 'swarm-note', 'hidden');

    enterReaderRun('run-4', FakeSequentialSwarm::class);

    $contents = renderedContents();

    expect($contents)->toContain('run-note: visible');
    expect($contents)->not->toContain('swarm-note: hidden');
});

test('honours a wide policy: other scopes are included', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-5', 'run-note', 'visible');
    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeWideViewPropagationSwarm::class, 'swarm-note', 'also-visible');

    enterReaderRun('run-5', FakeWideViewPropagationSwarm::class);

    $contents = renderedContents();

    expect($contents)->toContain('run-note: visible');
    expect($contents)->toContain('swarm-note: also-visible');
});
