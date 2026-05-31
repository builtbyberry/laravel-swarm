<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\DefaultPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalSingleRouteSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeWideViewPropagationSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Tests\Support\WideViewPropagationPolicy;

/**
 * The propagation policy decides which memory entries an agent sees at
 * invocation. The default policy presents the Run-scoped view only, preserving
 * pre-v0.10 behaviour; a per-swarm or config-bound policy can widen it. Every
 * runner consults the policy at the shared snapshot chokepoint, so the frozen
 * view mirrors exactly what the policy returns.
 */
beforeEach(function () {
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
            ],
        ]),
    ]);
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    $this->recorder = new RecordingSnapshotsMemory;
    $this->app->instance(SnapshotsMemory::class, $this->recorder);
});

/**
 * Flatten every MemoryEntry the runners handed to the recorder across all
 * snapshot calls.
 *
 * @return array<int, MemoryEntry>
 */
function capturedEntries(RecordingSnapshotsMemory $recorder): array
{
    $entries = [];

    foreach ($recorder->snapshotCalls as $call) {
        foreach ($call['entries'] ?? [] as $entry) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

test('default policy presents the Run-scoped view only across runners', function (string $swarmClass) {
    // Seed a Swarm-scoped entry the view will gather as a candidate. The
    // default policy must drop it so the agent sees only the Run scope.
    app(SwarmMemory::class)->put(MemoryScope::Swarm, $swarmClass, 'shared-note', 'swarm-value');

    $swarmClass::make()->run('a-task');

    /** @var RecordingSnapshotsMemory $recorder */
    $recorder = $this->recorder;

    $entries = capturedEntries($recorder);
    // Every runner passed a non-null policy-built list to the recorder.
    expect($recorder->snapshotCalls)->not->toBeEmpty();
    foreach ($recorder->snapshotCalls as $call) {
        expect($call['entries'])->toBeArray();
    }

    // No non-Run scope leaks into the agent-visible view.
    foreach ($entries as $entry) {
        expect($entry->scope)->toBe(MemoryScope::Run);
    }
    $keys = array_map(static fn (MemoryEntry $entry): string => $entry->key, $entries);
    expect($keys)->not->toContain('shared-note');
})->with([
    'sequential' => FakeSequentialSwarm::class,
    'parallel' => FakeParallelSwarm::class,
    'hierarchical' => FakeHierarchicalSingleRouteSwarm::class,
]);

test('a per-swarm #[PropagationPolicy] attribute widens the agent-visible view', function () {
    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeWideViewPropagationSwarm::class, 'shared-note', 'swarm-value');

    FakeWideViewPropagationSwarm::make()->run('a-task');

    /** @var RecordingSnapshotsMemory $recorder */
    $recorder = $this->recorder;

    $swarmScoped = array_filter(
        capturedEntries($recorder),
        static fn (MemoryEntry $entry): bool => $entry->scope === MemoryScope::Swarm,
    );

    expect($swarmScoped)->not->toBeEmpty();
    expect(array_values($swarmScoped)[0]->key)->toBe('shared-note');
});

test('the config-bound default policy applies when no attribute is present', function () {
    config()->set('swarm.memory.propagation_policy', WideViewPropagationPolicy::class);

    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeSequentialSwarm::class, 'shared-note', 'swarm-value');

    FakeSequentialSwarm::make()->run('a-task');

    /** @var RecordingSnapshotsMemory $recorder */
    $recorder = $this->recorder;

    $swarmScoped = array_filter(
        capturedEntries($recorder),
        static fn (MemoryEntry $entry): bool => $entry->scope === MemoryScope::Swarm,
    );

    expect($swarmScoped)->not->toBeEmpty();
});

test('the per-swarm attribute beats the config-bound default', function () {
    // Config points at the Run-only default; the swarm's attribute widens it.
    config()->set('swarm.memory.propagation_policy', DefaultPropagationPolicy::class);

    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeWideViewPropagationSwarm::class, 'shared-note', 'swarm-value');

    FakeWideViewPropagationSwarm::make()->run('a-task');

    /** @var RecordingSnapshotsMemory $recorder */
    $recorder = $this->recorder;

    $swarmScoped = array_filter(
        capturedEntries($recorder),
        static fn (MemoryEntry $entry): bool => $entry->scope === MemoryScope::Swarm,
    );

    expect($swarmScoped)->not->toBeEmpty();
});

test('a policy class that does not implement the contract throws', function () {
    config()->set('swarm.memory.propagation_policy', stdClass::class);

    expect(fn () => FakeSequentialSwarm::make()->run('a-task'))
        ->toThrow(SwarmException::class, 'must implement');
});
