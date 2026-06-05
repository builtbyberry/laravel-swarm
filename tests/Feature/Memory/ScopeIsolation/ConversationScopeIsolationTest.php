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
 * addressed by conversation id, so two conversations never share an entry. The
 * contract is pinned here at three boundaries:
 *
 * 1. Per-conversation addressing, asserted at the store boundary — entries keyed
 *    by one conversation id never collide with another's.
 * 2. The no-handle skip, asserted at the agent-visible-view boundary — a
 *    propagation policy that declares Conversation scope surfaces nothing when
 *    the run carries no conversation id, because `AgentVisibleMemoryView`
 *    resolves the Conversation scope_id to null and skips the scope.
 * 3. Per-conversation surfacing, asserted at the same boundary — once a run is
 *    bound to a conversation via {@see RunContext::withConversationId()}, that
 *    conversation's entries surface to the agent and another conversation's do
 *    not.
 *
 * The exhaustive end-to-end isolation matrix (multiple agents, topologies, and
 * write-back) rides on this and is tracked in #168.
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

test('a policy that declares Conversation scope receives nothing when the run has no conversation id', function () {
    // With no conversation id bound to the run, AgentVisibleMemoryView resolves
    // the Conversation scope_id to null and skips the scope — so even a policy
    // that explicitly declares Conversation surfaces nothing, while the Run scope
    // the same policy declares IS gathered. The entry still lives in the store; it
    // just has no run-bound conversation to surface under.
    config()->set('swarm.memory.propagation_policy', ConversationDeclaringPropagationPolicy::class);

    app(SwarmMemory::class)->put(MemoryScope::Conversation, 'conv-1', 'preference', 'concise');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'conv-iso-run', 'run-note', 'visible');

    FakeSequentialSwarm::make()->run(RunContext::from('task', 'conv-iso-run'));

    $entries = capturedEntries($this->recorder);
    $scopes = array_map(static fn (MemoryEntry $entry): MemoryScope => $entry->scope, $entries);
    $values = array_map(static fn (MemoryEntry $entry): mixed => $entry->value, $entries);

    // The declared-but-unaddressable Conversation scope is skipped; the declared
    // Run scope is gathered (proving the policy ran); the entry still lives in the
    // store — it just cannot be surfaced without a bound conversation id.
    expect($scopes)->not->toContain(MemoryScope::Conversation)
        ->and($values)->toContain('visible')
        ->and(app(SwarmMemory::class)->get(MemoryScope::Conversation, 'conv-1', 'preference'))->toBe('concise');
});

test('a run bound to a conversation surfaces that conversation\'s entries and isolates others', function () {
    // Once the run carries a conversation id, AgentVisibleMemoryView resolves the
    // Conversation scope to that id and gathers it. Two conversations are seeded;
    // the run is bound to conv-1, so only conv-1's entry surfaces — conv-2's is
    // addressed under a different id and never reaches this agent's view.
    config()->set('swarm.memory.propagation_policy', ConversationDeclaringPropagationPolicy::class);

    app(SwarmMemory::class)->put(MemoryScope::Conversation, 'conv-1', 'preference', 'concise');
    app(SwarmMemory::class)->put(MemoryScope::Conversation, 'conv-2', 'preference', 'verbose');

    FakeSequentialSwarm::make()->run(
        RunContext::from('task', 'conv-surfacing-run')->withConversationId('conv-1'),
    );

    $entries = capturedEntries($this->recorder);
    $scopes = array_map(static fn (MemoryEntry $entry): MemoryScope => $entry->scope, $entries);
    $values = array_map(static fn (MemoryEntry $entry): mixed => $entry->value, $entries);

    // conv-1's entry surfaces under the Conversation scope; conv-2's never does.
    expect($scopes)->toContain(MemoryScope::Conversation)
        ->and($values)->toContain('concise')
        ->and($values)->not->toContain('verbose');
});
