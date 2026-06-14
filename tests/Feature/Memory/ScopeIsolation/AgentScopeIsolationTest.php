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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalParallelWideViewSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeWideViewPropagationSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;

/**
 * Agent scope is per-agent-class persistent state, addressed by the agent's
 * class string. Two agent classes never share an entry, and when a wide policy
 * gathers the Agent scope on a live runner (which knows the concrete agent) only
 * the running agent class's entries are surfaced.
 */
pest()->group('compliance');

beforeEach(function () {
    FakeResearcher::fake(fn (): string => 'research-out');
    FakeWriter::fake(fn (): string => 'writer-out');

    $this->recorder = new RecordingSnapshotsMemory;
    $this->app->instance(SnapshotsMemory::class, $this->recorder);
});

test('agent-scoped memory is keyed by agent class string', function () {
    $memory = app(SwarmMemory::class);

    $memory->put(MemoryScope::Agent, FakeResearcher::class, 'skill', 'research');
    $memory->put(MemoryScope::Agent, FakeWriter::class, 'skill', 'writing');

    expect($memory->get(MemoryScope::Agent, FakeResearcher::class, 'skill'))->toBe('research')
        ->and($memory->get(MemoryScope::Agent, FakeWriter::class, 'skill'))->toBe('writing');

    $values = array_map(
        static fn (MemoryEntry $entry): mixed => $entry->value,
        $memory->all(MemoryScope::Agent, FakeResearcher::class),
    );

    expect($values)->toBe(['research']);
});

test('a wide-view swarm surfaces only the running agent class Agent-scoped entries', function () {
    // Two classes carry the same key in Agent scope; the running agent
    // (FakeResearcher) must see only its own.
    app(SwarmMemory::class)->put(MemoryScope::Agent, FakeResearcher::class, 'agent-note', 'researcher-secret');
    app(SwarmMemory::class)->put(MemoryScope::Agent, FakeWriter::class, 'agent-note', 'writer-secret');

    FakeWideViewPropagationSwarm::make()->run(RunContext::from('task', 'agent-iso-run'));

    $agentScoped = array_filter(
        capturedEntries($this->recorder),
        static fn (MemoryEntry $entry): bool => $entry->scope === MemoryScope::Agent,
    );

    expect($agentScoped)->not->toBeEmpty();
    foreach ($agentScoped as $entry) {
        expect($entry->scopeId)->toBe(FakeResearcher::class)
            ->and($entry->value)->not->toBe('writer-secret');
    }
});

test('hierarchical-parallel branches include Agent-scoped entries in their snapshots', function () {
    // Regression for the bug where resolveParallelWorker() return value was
    // discarded and null was passed to view->present(), causing MemoryScope::Agent
    // to be silently skipped on the concurrent hierarchical path.
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ]),
    ]);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    app(SwarmMemory::class)->put(MemoryScope::Agent, FakeWriter::class, 'speciality', 'writing');
    app(SwarmMemory::class)->put(MemoryScope::Agent, FakeEditor::class, 'speciality', 'editing');

    FakeHierarchicalParallelWideViewSwarm::make()->run(RunContext::from('task', 'hier-par-agent-scope'));

    $agentScoped = array_filter(
        capturedEntries($this->recorder),
        static fn (MemoryEntry $entry): bool => $entry->scope === MemoryScope::Agent,
    );

    $scopeIds = array_unique(array_map(
        static fn (MemoryEntry $entry): string => $entry->scopeId,
        $agentScoped,
    ));

    expect($agentScoped)->not->toBeEmpty()
        ->and($scopeIds)->toContain(FakeWriter::class)
        ->and($scopeIds)->toContain(FakeEditor::class);

    // Per-branch isolation: each snapshot that holds Agent-scoped entries must
    // carry entries for exactly one agent class — the writer and editor branches
    // must not bleed into each other's frozen view.
    foreach ($this->recorder->snapshotCalls as $call) {
        $agentScopedInStep = array_values(array_filter(
            $call['entries'] ?? [],
            static fn (MemoryEntry $entry): bool => $entry->scope === MemoryScope::Agent,
        ));

        if (empty($agentScopedInStep)) {
            continue;
        }

        $uniqueIds = array_unique(array_map(
            static fn (MemoryEntry $entry): string => $entry->scopeId,
            $agentScopedInStep,
        ));

        expect($uniqueIds)->toHaveCount(1);
    }
});
