<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeWideViewPropagationSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;

/**
 * Conversation scope is shared across runs in the same conversation thread and
 * addressed by conversation id, so two conversations never share an entry. It is
 * asserted at the store boundary because the runtime exposes no conversation
 * handle yet — `AgentVisibleMemoryView` resolves the Conversation scope_id to
 * null and skips it even when a policy declares it, so no agent-visible view can
 * surface Conversation-scoped memory. Both halves of that contract are pinned
 * here.
 */
pest()->group('compliance');

beforeEach(function () {
    FakeResearcher::fake(fn (): string => 'research-out');

    $this->recorder = new RecordingSnapshotsMemory;
    $this->app->instance(SnapshotsMemory::class, $this->recorder);
});

test('conversation-scoped entries are addressed per conversation id', function () {
    $memory = app(SwarmMemory::class);

    $memory->put(MemoryScope::Conversation, 'conv-1', 'preference', 'concise');
    $memory->put(MemoryScope::Conversation, 'conv-2', 'preference', 'verbose');

    expect($memory->get(MemoryScope::Conversation, 'conv-1', 'preference'))->toBe('concise')
        ->and($memory->get(MemoryScope::Conversation, 'conv-2', 'preference'))->toBe('verbose');

    $values = array_map(
        static fn (MemoryEntry $entry): mixed => $entry->value,
        $memory->all(MemoryScope::Conversation, 'conv-1'),
    );

    expect($values)->toBe(['concise']);
});

test('the agent-visible view never surfaces Conversation-scoped memory at runtime', function () {
    // Seed a Conversation entry, then run a swarm whose policy gathers every
    // *runtime-resolvable* scope. The Conversation scope has no scope_id to key
    // on, so it can never reach an agent — documents the deliberate v0.10 gap.
    app(SwarmMemory::class)->put(MemoryScope::Conversation, 'conv-1', 'preference', 'concise');

    FakeWideViewPropagationSwarm::make()->run(RunContext::from('task', 'conv-iso-run'));

    $conversationScoped = array_filter(
        capturedEntries($this->recorder),
        static fn (MemoryEntry $entry): bool => $entry->scope === MemoryScope::Conversation,
    );

    // The entry exists in the store, but no agent-visible view contains it.
    expect(app(SwarmMemory::class)->get(MemoryScope::Conversation, 'conv-1', 'preference'))->toBe('concise')
        ->and($conversationScoped)->toBeEmpty();
});
