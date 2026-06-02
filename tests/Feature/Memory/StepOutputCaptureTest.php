<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\ConversationPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\SwarmMemoryKeys;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalSingleRouteSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;

/**
 * Runners persist each step's output to Run scope under the reserved key
 * `swarm:step.{n}.output` (see SwarmMemoryKeys). These tests assert the write
 * against *raw* Run memory — the surface that always holds the keys — because
 * the default propagation policy deliberately hides them from the agent-visible
 * snapshot view.
 */
beforeEach(function () {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

/**
 * @return array<int, string>
 */
function rawStepOutputs(string $runId): array
{
    /** @var array<int, MemoryEntry> $entries */
    $entries = app(SwarmMemory::class)->all(MemoryScope::Run, $runId);

    $outputs = [];
    foreach ($entries as $entry) {
        if (SwarmMemoryKeys::isStepOutput($entry->key)) {
            $outputs[$entry->key] = $entry->value;
        }
    }

    return $outputs;
}

test('the sequential runner captures each step output to raw Run memory', function () {
    FakeSequentialSwarm::make()->run(RunContext::from('go', 'seq-capture'));

    expect(rawStepOutputs('seq-capture'))->toBe([
        'swarm:step.0.output' => 'research-out',
        'swarm:step.1.output' => 'writer-out',
        'swarm:step.2.output' => 'editor-out',
    ]);
});

test('the parallel runner captures each branch output to raw Run memory', function () {
    FakeParallelSwarm::make()->run(RunContext::from('go', 'par-capture'));

    expect(rawStepOutputs('par-capture'))->toBe([
        'swarm:step.0.output' => 'research-out',
        'swarm:step.1.output' => 'writer-out',
        'swarm:step.2.output' => 'editor-out',
    ]);
});

test('the hierarchical runner captures step outputs to raw Run memory', function () {
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
            ],
        ]),
    ]);

    FakeHierarchicalSingleRouteSwarm::make()->run(RunContext::from('go', 'hier-capture'));

    $outputs = rawStepOutputs('hier-capture');
    // Coordinator (step 0) + worker (step 1) both record an output.
    expect($outputs)->toHaveKey('swarm:step.0.output');
    expect($outputs['swarm:step.1.output'])->toBe('writer-out');
});

test('the streaming runner captures step outputs to raw Run memory', function () {
    $response = FakeSequentialSwarm::make()->stream(RunContext::from('go', 'stream-capture'));
    iterator_to_array($response);

    expect(rawStepOutputs('stream-capture'))->toBe([
        'swarm:step.0.output' => 'research-out',
        'swarm:step.1.output' => 'writer-out',
        'swarm:step.2.output' => 'editor-out',
    ]);
});

test('disabling capture_step_output writes no reserved keys', function () {
    config()->set('swarm.memory.capture_step_output', false);

    FakeSequentialSwarm::make()->run(RunContext::from('go', 'no-capture'));

    expect(rawStepOutputs('no-capture'))->toBe([]);
    // The pre-existing last_output key is unaffected by the flag.
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'no-capture', 'last_output'))->toBe('editor-out');
});

test('the default policy hides step-output keys from the agent view, but they persist in raw memory', function () {
    $recorder = new RecordingSnapshotsMemory;
    $this->app->instance(SnapshotsMemory::class, $recorder);

    FakeSequentialSwarm::make()->run(RunContext::from('go', 'hidden-capture'));

    // Every entry the runner froze into a snapshot under the default policy...
    foreach ($recorder->snapshotCalls as $call) {
        foreach ($call['entries'] ?? [] as $entry) {
            expect(SwarmMemoryKeys::isStepOutput($entry->key))->toBeFalse();
        }
    }

    // ...yet the keys were written to raw Run memory all the same.
    expect(rawStepOutputs('hidden-capture'))->toHaveCount(3);
});

test('under ConversationPropagationPolicy each step snapshot sees only prior step outputs', function () {
    config()->set('swarm.memory.propagation_policy', ConversationPropagationPolicy::class);
    app()->forgetInstance(MemoryPropagationPolicy::class);

    $recorder = new RecordingSnapshotsMemory;
    $this->app->instance(SnapshotsMemory::class, $recorder);

    FakeSequentialSwarm::make()->run(RunContext::from('go', 'transcript-capture'));

    $byStep = [];
    foreach ($recorder->snapshotCalls as $call) {
        $byStep[$call['step_index']] = array_map(
            static fn (MemoryEntry $entry): string => $entry->key,
            $call['entries'] ?? [],
        );
    }

    // Off-by-one is the contract: a step never sees its own (not-yet-produced)
    // output, only the prior steps' — and the final output is in no snapshot.
    expect($byStep[0])->toBe([]);
    expect($byStep[1])->toBe(['swarm:step.0.output']);
    expect($byStep[2])->toBe(['swarm:step.0.output', 'swarm:step.1.output']);
});
