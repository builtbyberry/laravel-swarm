<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\ConversationDeclaringPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;

/**
 * Conversation scope is shared across runs in the same conversation thread and
 * addressed by conversation id, so two conversations never share an entry. Two
 * halves of that contract are pinned here:
 *
 * 1. Per-conversation addressing, asserted at the store boundary — entries keyed
 *    by one conversation id never collide with another's.
 * 2. The runtime skip, asserted at the agent-visible-view boundary — even a
 *    propagation policy that explicitly declares Conversation scope surfaces
 *    nothing, because `AgentVisibleMemoryView` resolves the Conversation scope_id
 *    to null (the v0.10 runtime exposes no conversation handle).
 *
 * End-to-end per-conversation *surfacing* is therefore untestable until that
 * handle exists — tracked in #168.
 */
pest()->group('compliance');

beforeEach(function () {
    FakeResearcher::fake(fn (): string => 'research-out');
    FakeWriter::fake(fn (): string => 'writer-out');
    FakeEditor::fake(fn (): string => 'editor-out');

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

test('a policy that declares Conversation scope still receives nothing at runtime', function () {
    // A policy that explicitly asks for Conversation scope is the real test of
    // the skip: AgentVisibleMemoryView resolves the Conversation scope_id to null
    // and never gathers it (src/Memory/AgentVisibleMemoryView.php:79), so no
    // agent-visible view can surface Conversation memory — while the Run scope the
    // same policy declares IS gathered. (End-to-end per-conversation surfacing is
    // untestable until the runtime exposes a conversation handle — see #168.)
    config()->set('swarm.memory.propagation_policy', ConversationDeclaringPropagationPolicy::class);

    app(SwarmMemory::class)->put(MemoryScope::Conversation, 'conv-1', 'preference', 'concise');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'conv-iso-run', 'run-note', 'visible');

    FakeSequentialSwarm::make()->run(RunContext::from('task', 'conv-iso-run'));

    $entries = capturedEntries($this->recorder);
    $scopes = array_map(static fn (MemoryEntry $entry): MemoryScope => $entry->scope, $entries);
    $values = array_map(static fn (MemoryEntry $entry): mixed => $entry->value, $entries);

    // The declared-but-unresolvable Conversation scope is skipped; the declared
    // Run scope is gathered (proving the policy ran); the entry still lives in the
    // store — it just cannot be surfaced.
    expect($scopes)->not->toContain(MemoryScope::Conversation)
        ->and($values)->toContain('visible')
        ->and(app(SwarmMemory::class)->get(MemoryScope::Conversation, 'conv-1', 'preference'))->toBe('concise');
});
