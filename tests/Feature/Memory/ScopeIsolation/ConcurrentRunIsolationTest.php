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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeWideViewPropagationSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;

/**
 * Run scope is the per-run-instance scope: every entry is keyed by the concrete
 * run id, so two runs can never read each other's Run-scoped memory. The sync
 * test harness can't run two processes at once, so "concurrent" is simulated as
 * two runs with distinct run ids whose agent-visible views are grouped per run
 * (via the snapshot recorder's run_id) and asserted disjoint. The guarantee is
 * structural — it holds even under a wide propagation policy.
 */
pest()->group('compliance');

beforeEach(function () {
    FakeResearcher::fake(fn (): string => 'research-out');
    FakeWriter::fake(fn (): string => 'writer-out');
    FakeEditor::fake(fn (): string => 'editor-out');

    $this->recorder = new RecordingSnapshotsMemory;
    $this->app->instance(SnapshotsMemory::class, $this->recorder);
});

test("run B never sees run A's run-scoped memory", function () {
    // Seed each run's own Run scope. Run B must see its own note and never A's.
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-a', 'note', 'from-A');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-b', 'note', 'from-B');

    FakeSequentialSwarm::make()->run(RunContext::from('task', 'run-b'));

    $values = array_map(
        static fn (MemoryEntry $entry): mixed => $entry->value,
        capturedEntriesForRun($this->recorder, 'run-b'),
    );

    expect($values)->toContain('from-B')->not->toContain('from-A');
});

test('two interleaved runs each see only their own run-scoped entries', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-a', 'note', 'from-A');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-b', 'note', 'from-B');

    FakeSequentialSwarm::make()->run(RunContext::from('task', 'run-a'));
    FakeSequentialSwarm::make()->run(RunContext::from('task', 'run-b'));

    $aValues = array_map(
        static fn (MemoryEntry $entry): mixed => $entry->value,
        capturedEntriesForRun($this->recorder, 'run-a'),
    );
    $bValues = array_map(
        static fn (MemoryEntry $entry): mixed => $entry->value,
        capturedEntriesForRun($this->recorder, 'run-b'),
    );

    expect($aValues)->toContain('from-A')->not->toContain('from-B');
    expect($bValues)->toContain('from-B')->not->toContain('from-A');
});

test('a wide-view swarm still isolates Run scope across two runs', function () {
    // Even a policy that gathers every scope keys Run by run id, so the secret
    // seeded into run A can never surface in run B.
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-a', 'wide-secret', 'A-only');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-b', 'wide-note', 'B-own');

    FakeWideViewPropagationSwarm::make()->run(RunContext::from('task', 'run-b'));

    $keys = array_map(
        static fn (MemoryEntry $entry): string => $entry->key,
        capturedEntriesForRun($this->recorder, 'run-b'),
    );

    expect($keys)->toContain('wide-note')->not->toContain('wide-secret');
});
