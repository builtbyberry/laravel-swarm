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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRestrictiveHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRestrictiveParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRestrictivePropagationSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Tests\Support\RestrictivePropagationPolicy;

/**
 * A custom propagation policy must enforce its filter at every live runner's
 * snapshot chokepoint. The RestrictivePropagationPolicy gathers candidates from
 * Run, Agent, and Swarm scope but presents only the single allow-listed key, so
 * every entry an agent sees — on the sequential, parallel, and hierarchical
 * runners — must be that one key, with the disallowed Run and Swarm candidates
 * dropped. The durable branch advancer (the fourth runner) is covered in
 * DurableBranchPropagationTest, which reads the frozen snapshot back from the
 * real recorder.
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
 * Seed an allow-listed Run entry alongside disallowed Run and Swarm candidates,
 * so a passing test proves the policy dropped real candidates rather than seeing
 * an empty view.
 */
function seedRestrictiveCandidates(string $runId, string $swarmClass): void
{
    $memory = app(SwarmMemory::class);

    $memory->put(MemoryScope::Run, $runId, RestrictivePropagationPolicy::ALLOWED_KEY, 'keep-me');
    $memory->put(MemoryScope::Run, $runId, 'disallowed-note', 'drop-me-run');
    $memory->put(MemoryScope::Swarm, $swarmClass, 'shared-note', 'drop-me-swarm');
}

/**
 * @param  array<int, MemoryEntry>  $entries
 */
function expectOnlyAllowedKey(array $entries): void
{
    expect($entries)->not->toBeEmpty();

    $keys = array_map(static fn (MemoryEntry $entry): string => $entry->key, $entries);
    expect(array_values(array_unique($keys)))->toBe([RestrictivePropagationPolicy::ALLOWED_KEY]);

    $values = array_map(static fn (MemoryEntry $entry): mixed => $entry->value, $entries);
    expect($values)->toContain('keep-me')
        ->not->toContain('drop-me-run')
        ->not->toContain('drop-me-swarm');
}

test('restrictive policy keeps only the allow-listed key on the sequential runner', function () {
    seedRestrictiveCandidates('restrictive-seq', FakeRestrictivePropagationSwarm::class);

    FakeRestrictivePropagationSwarm::make()->run(RunContext::from('task', 'restrictive-seq'));

    expectOnlyAllowedKey(capturedEntries($this->recorder));
});

test('restrictive policy keeps only the allow-listed key on the parallel runner', function () {
    seedRestrictiveCandidates('restrictive-par', FakeRestrictiveParallelSwarm::class);

    FakeRestrictiveParallelSwarm::make()->run(RunContext::from('task', 'restrictive-par'));

    expectOnlyAllowedKey(capturedEntries($this->recorder));
});

test('restrictive policy keeps only the allow-listed key on the hierarchical runner', function () {
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
            ],
        ]),
    ]);

    seedRestrictiveCandidates('restrictive-hier', FakeRestrictiveHierarchicalSwarm::class);

    FakeRestrictiveHierarchicalSwarm::make()->run(RunContext::from('task', 'restrictive-hier'));

    expectOnlyAllowedKey(capturedEntries($this->recorder));
});

test('the restrictive policy drops a Run-scoped entry whose key is not allow-listed', function () {
    // Two Run-scoped entries: the DefaultPropagationPolicy would keep both (it
    // filters only by scope); the restrictive policy keeps only the allow-listed
    // key. This is the override the custom policy exists to express.
    $memory = app(SwarmMemory::class);
    $memory->put(MemoryScope::Run, 'restrictive-override', RestrictivePropagationPolicy::ALLOWED_KEY, 'keep-me');
    $memory->put(MemoryScope::Run, 'restrictive-override', 'another-note', 'drop-me');

    FakeRestrictivePropagationSwarm::make()->run(RunContext::from('task', 'restrictive-override'));

    $values = array_map(
        static fn (MemoryEntry $entry): mixed => $entry->value,
        capturedEntries($this->recorder),
    );

    expect($values)->toContain('keep-me')->not->toContain('drop-me');
});
