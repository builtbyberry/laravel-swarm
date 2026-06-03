<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\ConversationPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\SwarmMemoryKeys;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalSingleRouteSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Tests\Support\RedactingMemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingMemoryCapturePolicy;

/**
 * Runners persist each step's output to Run scope under the reserved key
 * `swarm:step.{n}.output` (see SwarmMemoryKeys). These tests assert the write
 * against *raw* Run memory — the surface that always holds the keys — because
 * the default propagation policy deliberately hides them from the agent-visible
 * snapshot view.
 *
 * Capture is opt-in (off by default), so the tests that exercise it enable
 * `swarm.memory.capture_step_output` explicitly.
 */
beforeEach(function () {
    config()->set('swarm.memory.capture_step_output', true);
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

/** Install a MemoryCapturePolicy and rebuild the decorated store/facade. */
function bindStepCapturePolicy(MemoryCapturePolicy $policy): void
{
    app()->instance(MemoryCapturePolicy::class, $policy);
    app()->forgetInstance(MemoryStore::class);
    app()->forgetInstance(SwarmMemory::class);
}

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

test('capture is off by default — no reserved keys unless enabled', function () {
    // Simulate the shipped default (beforeEach turns capture on for the other
    // specs); with it off, a run writes no step-output keys.
    config()->set('swarm.memory.capture_step_output', false);

    FakeSequentialSwarm::make()->run(RunContext::from('go', 'no-capture'));

    expect(rawStepOutputs('no-capture'))->toBe([]);
    // The pre-existing last_output key is unaffected by the flag.
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'no-capture', 'last_output'))->toBe('editor-out');
});

test('the shipped config default for capture_step_output is off', function () {
    // Locks the opt-in default at the source, independent of any test override.
    $config = require dirname(__DIR__, 3).'/config/swarm.php';

    expect($config['memory']['capture_step_output'])->toBeFalse();
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

test('a MemoryCapturePolicy redacts a captured step-output key at the write boundary', function () {
    bindStepCapturePolicy(new RedactingMemoryCapturePolicy([SwarmMemoryKeys::stepOutput(0)]));

    FakeSequentialSwarm::make()->run(RunContext::from('go', 'redact-capture'));

    $memory = app(SwarmMemory::class);
    // The targeted step-0 key is redacted in memory (never stored in the clear)…
    expect($memory->get(MemoryScope::Run, 'redact-capture', SwarmMemoryKeys::stepOutput(0)))
        ->toBe(SwarmCapture::REDACTED);
    // …while the untargeted step keys persist normally.
    expect($memory->get(MemoryScope::Run, 'redact-capture', SwarmMemoryKeys::stepOutput(1)))
        ->toBe('writer-out');
});

test('a MemoryCapturePolicy can skip a captured step-output key entirely', function () {
    bindStepCapturePolicy(new SkippingMemoryCapturePolicy([SwarmMemoryKeys::stepOutput(0)]));

    FakeSequentialSwarm::make()->run(RunContext::from('go', 'skip-capture'));

    $memory = app(SwarmMemory::class);
    expect($memory->get(MemoryScope::Run, 'skip-capture', SwarmMemoryKeys::stepOutput(0)))->toBeNull();
    expect($memory->get(MemoryScope::Run, 'skip-capture', SwarmMemoryKeys::stepOutput(1)))->toBe('writer-out');
});

test('captured step output is stored full-fidelity, not truncated by the payload limiter', function () {
    // An output well over the configured byte limit, with truncate-on-overflow
    // so the run does not fail outright.
    $long = str_repeat('x', 5000);
    FakeResearcher::fake([$long]);
    config()->set('swarm.limits.max_output_bytes', 100);
    config()->set('swarm.limits.overflow', 'truncate');

    FakeSequentialSwarm::make()->run(RunContext::from('go', 'fidelity-capture'));

    // The step-output key keeps the COMPLETE output (audit fidelity) even though
    // the artifact/history/final-output copies are truncated to the limit.
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'fidelity-capture', SwarmMemoryKeys::stepOutput(0)))
        ->toBe($long);
});
