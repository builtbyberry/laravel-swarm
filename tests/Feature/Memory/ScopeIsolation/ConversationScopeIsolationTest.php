<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalSingleRouteSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalSingleWorkerSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\ConversationDeclaringPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
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
 * 3. Per-conversation surfacing, asserted at the same boundary across every live
 *    runner topology (sequential, parallel, hierarchical, static-hierarchical) —
 *    once a run is bound to a conversation via
 *    {@see RunContext::withConversationId()}, that conversation's entries surface
 *    to the agent and another conversation's do not. The durable runner is
 *    covered separately in ConversationScopeDurableTest (it reads the frozen
 *    snapshot back from the real recorder).
 *
 * Cross-run write-back (an entry written in run 1 surfaces in run 2 with the same
 * conversation id) is proved implicitly: the store-level test seeds a Conversation
 * entry outside any run and the topology tests pick it up, which is the same store
 * path a prior run's Remember tool would have taken.
 */
pest()->group('compliance');

beforeEach(function () {
    FakeResearcher::fake(fn (): string => 'research-out');
    FakeWriter::fake(fn (): string => 'writer-out');
    FakeEditor::fake(fn (): string => 'editor-out');

    $this->recorder = new RecordingSnapshotsMemory;
    $this->app->instance(SnapshotsMemory::class, $this->recorder);
});

/**
 * Run the given topology bound to a conversation id, using a plain (no
 * #[PropagationPolicy]) fixture per topology so the globally-configured
 * ConversationDeclaringPropagationPolicy applies. Mirrors the per-runner setup
 * used by RestrictivePolicyAcrossRunnersTest and StepOutputCaptureTest.
 */
function runConversationBoundTopology(string $topology, string $conversationId): void
{
    $bind = fn (string $runId): RunContext => RunContext::from('task', $runId)->withConversationId($conversationId);

    if ($topology === 'hierarchical') {
        FakeHierarchicalCoordinator::fake([
            HierarchicalTestPlan::make('writer_node', [
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'writer-task',
                ],
            ]),
        ]);

        FakeHierarchicalSingleRouteSwarm::make()->run($bind('conv-hier-run'));

        return;
    }

    match ($topology) {
        'sequential' => FakeSequentialSwarm::make()->run($bind('conv-seq-run')),
        'parallel' => FakeParallelSwarm::make()->run($bind('conv-par-run')),
        'static-hierarchical' => FakeStaticHierarchicalSingleWorkerSwarm::make()->run($bind('conv-static-run')),
    };
}

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

test('a run bound to a conversation surfaces that conversation\'s entries and isolates others', function (string $topology) {
    // Once the run carries a conversation id, AgentVisibleMemoryView resolves the
    // Conversation scope to that id and gathers it — on every live runner. Two
    // conversations are seeded; the run is bound to conv-1, so only conv-1's entry
    // surfaces. conv-2 is addressed under a different id and never reaches the
    // agent's view: per-conversation isolation holds across topologies.
    config()->set('swarm.memory.propagation_policy', ConversationDeclaringPropagationPolicy::class);

    app(SwarmMemory::class)->put(MemoryScope::Conversation, 'conv-1', 'preference', 'concise');
    app(SwarmMemory::class)->put(MemoryScope::Conversation, 'conv-2', 'preference', 'verbose');

    runConversationBoundTopology($topology, 'conv-1');

    $entries = capturedEntries($this->recorder);
    $scopes = array_map(static fn (MemoryEntry $entry): MemoryScope => $entry->scope, $entries);
    $values = array_map(static fn (MemoryEntry $entry): mixed => $entry->value, $entries);

    expect($scopes)->toContain(MemoryScope::Conversation)
        ->and($values)->toContain('concise')
        ->and($values)->not->toContain('verbose');
})->with(['sequential', 'parallel', 'hierarchical', 'static-hierarchical']);
