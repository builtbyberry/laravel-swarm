<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalSingleRouteSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRichStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalSingleRichWorkerSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamConcurrentSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;

/**
 * Verifies each runner calls SnapshotsMemory::snapshot() exactly once per
 * agent invocation, immediately before $agent->prompt() / $agent->stream().
 *
 * The full byte-fidelity story is covered by MemorySnapshotTest against the
 * database-backed recorder; this file only asserts the wiring — that runners
 * actually invoke the contract at the right point.
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

test('SequentialRunner snapshots memory before every agent invocation', function () {
    FakeSequentialSwarm::make()->run('original-task');

    /** @var RecordingSnapshotsMemory $recorder */
    $recorder = $this->recorder;
    expect($recorder->snapshotCalls)->toHaveCount(3);
    expect($recorder->snapshotCalls[0]['step_index'])->toBe(0);
    expect($recorder->snapshotCalls[1]['step_index'])->toBe(1);
    expect($recorder->snapshotCalls[2]['step_index'])->toBe(2);
    expect($recorder->snapshotCalls[0]['run_id'])->toBe($recorder->snapshotCalls[1]['run_id']);
});

test('ParallelRunner snapshots memory before every parallel branch', function () {
    FakeParallelSwarm::make()->run('shared-task');

    /** @var RecordingSnapshotsMemory $recorder */
    $recorder = $this->recorder;
    expect($recorder->snapshotCalls)->toHaveCount(3);
    $stepIndexes = array_column($recorder->snapshotCalls, 'step_index');
    sort($stepIndexes);
    expect($stepIndexes)->toBe([0, 1, 2]);
});

test('HierarchicalRunner snapshots memory for the coordinator and every worker', function () {
    FakeHierarchicalSingleRouteSwarm::make()->run('hierarchical-task');

    /** @var RecordingSnapshotsMemory $recorder */
    $recorder = $this->recorder;
    // Coordinator (index 0) plus at least one worker step.
    expect(count($recorder->snapshotCalls))->toBeGreaterThanOrEqual(2);
    expect($recorder->snapshotCalls[0]['step_index'])->toBe(0);
});

test('StaticHierarchicalStreamRunner streaming snapshots memory before every worker node', function () {
    $events = iterator_to_array(FakeStaticHierarchicalStreamSequentialSwarm::make()->stream('stream-task'));

    expect($events)->not->toBeEmpty();

    /** @var RecordingSnapshotsMemory $recorder */
    $recorder = $this->recorder;
    expect($recorder->snapshotCalls)->toHaveCount(2);
    expect($recorder->snapshotCalls[0]['step_index'])->toBe(0);
    expect($recorder->snapshotCalls[1]['step_index'])->toBe(1);
    expect($recorder->snapshotCalls[0]['run_id'])->toBe($recorder->snapshotCalls[1]['run_id']);
});

test('StaticHierarchicalStreamRunner streaming appends paired tool-call entries to the snapshot', function () {
    $events = iterator_to_array(FakeStaticHierarchicalSingleRichWorkerSwarm::make()->stream('stream-task'));

    expect($events)->not->toBeEmpty();

    /** @var RecordingSnapshotsMemory $recorder */
    $recorder = $this->recorder;
    expect($recorder->snapshotCalls)->toHaveCount(1);

    $appends = array_filter(
        $recorder->toolCallAppends,
        static fn (array $append): bool => $append['step_index'] === 0,
    );
    expect($appends)->toHaveCount(1);

    /** @var array{tool_call: array<string, mixed>} $append */
    $append = array_values($appends)[0];
    expect($append['tool_call']['name'])->toBe('search_docs');
    expect($append['tool_call']['arguments'])->toBe(['query' => 'swarm']);
    expect($append['tool_call']['result'])->toBe(['matches' => 1]);
});

test('StaticHierarchicalStreamRunner concurrent branches each receive a snapshot', function () {
    $events = iterator_to_array(FakeStaticHierarchicalStreamConcurrentSwarm::make()->stream('stream-task'));

    expect($events)->not->toBeEmpty();

    /** @var RecordingSnapshotsMemory $recorder */
    $recorder = $this->recorder;
    // 2 concurrent branches (researcher + writer) + 1 sequential editor node = 3 snapshots total.
    expect($recorder->snapshotCalls)->toHaveCount(3);

    $stepIndexes = array_column($recorder->snapshotCalls, 'step_index');
    sort($stepIndexes);
    expect($stepIndexes)->toBe([0, 1, 2]);

    // All snapshots share the same run id.
    $runIds = array_unique(array_column($recorder->snapshotCalls, 'run_id'));
    expect($runIds)->toHaveCount(1);
});

test('SequentialRunner streaming appends paired tool-call entries to the snapshot', function () {
    $events = iterator_to_array(FakeRichStreamingSwarm::make()->stream('streaming-task'));

    expect($events)->not->toBeEmpty();

    /** @var RecordingSnapshotsMemory $recorder */
    $recorder = $this->recorder;
    expect($recorder->snapshotCalls)->toHaveCount(3);

    // The RichStreamEditor fixture emits one ToolCall + matching ToolResult.
    // Snapshot recording pairs them into a single appendToolCall.
    $streamingStepAppends = array_filter(
        $recorder->toolCallAppends,
        static fn (array $append): bool => $append['step_index'] === 2,
    );
    expect($streamingStepAppends)->toHaveCount(1);

    /** @var array{tool_call: array<string, mixed>} $append */
    $append = array_values($streamingStepAppends)[0];
    expect($append['tool_call']['name'])->toBe('search_docs');
    expect($append['tool_call']['arguments'])->toBe(['query' => 'swarm']);
    expect($append['tool_call']['result'])->toBe(['matches' => 1]);
});
