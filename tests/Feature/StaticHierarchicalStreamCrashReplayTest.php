<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmStepRecorder;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryRecallAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryWritingFlakyStreamAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksStepWhenIndex;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ReplayWriteHierarchicalStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ReplayWriteSequentialStaticHierarchicalStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ReplayWriteStaticHierarchicalStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingStaticHierarchicalConcurrentRecallSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingStaticHierarchicalRecallSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingStaticHierarchicalUnpairedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Crash-replay durability for STREAMED static-hierarchical runs (OG1, built on
 * F1). Mirrors StreamingCrashReplayTest for the sequential runner:
 *
 *  - finally-flush: an abandoned worker stream persists its in-flight tool call;
 *  - sequential-node resume: a re-run replays the frozen value, not live drift;
 *  - concurrent-branch resume: each forked/spawned branch reconstructs its OWN
 *    frozen view (verified under the Sync concurrency driver).
 */
beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    config()->set('swarm.memory.replay_mode', ReplayMode::FrozenView->value);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    ActiveRunContext::flush();
    MemoryRecallAgent::reset();
    MemoryWritingFlakyStreamAgent::reset();
});

function seedStaticReplayWriterSnapshot(string $runId): void
{
    config()->set('swarm.static_hierarchical.stream_parallel_branches', 'sequential');
    seedStaticReplayRunHistory($runId);
    app(SnapshotsMemory::class)->snapshot($runId, 0, []);
    MemoryWritingFlakyStreamAgent::$attempts = 1;
}

function failStaticReplayStepRecording(): void
{
    $recorder = Mockery::mock(app(SwarmStepRecorder::class))->makePartial();
    $recorder->shouldReceive('completed')->once()->andThrow(new RuntimeException('static-step-recording-failed'));
    app()->instance(SwarmStepRecorder::class, $recorder);
    app()->forgetInstance(StaticHierarchicalStreamRunner::class);
    app()->forgetInstance(SwarmRunner::class);
}

dataset('static replay writer swarms', [
    'parallel branch' => ReplayWriteStaticHierarchicalStreamingSwarm::class,
    'plain worker' => ReplayWriteSequentialStaticHierarchicalStreamingSwarm::class,
]);

test('a retried static stream worker saves its write before the downstream node', function (string $swarmClass) {
    $runId = 'static-stream-retry-write-success-'.class_basename($swarmClass);
    seedStaticReplayWriterSnapshot($runId);

    iterator_to_array($swarmClass::make()->stream(RunContext::from('retry-write', $runId)));

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write'))->toBe('retry-value')
        ->and(MemoryRecallAgent::$seen)->toContain('retry-write: retry-value');
})->with('static replay writer swarms');

test('a retried dynamic stream worker saves its write before the downstream node', function () {
    $runId = 'dynamic-stream-retry-write-success';
    seedStreamReplayRunHistory($runId, ReplayWriteHierarchicalStreamingSwarm::class, 'hierarchical');
    app(SnapshotsMemory::class)->snapshot($runId, 1, []);
    MemoryWritingFlakyStreamAgent::$attempts = 1;
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('writer', [
            'writer' => [
                'type' => 'worker',
                'agent' => MemoryWritingFlakyStreamAgent::class,
                'prompt' => 'write-on-retry',
                'next' => 'reader',
            ],
            'reader' => [
                'type' => 'worker',
                'agent' => MemoryRecallAgent::class,
                'prompt' => 'read-retry-write',
                'next' => 'finish',
            ],
            'finish' => [
                'type' => 'finish',
                'output_from' => 'reader',
            ],
        ]),
    ]);

    iterator_to_array(ReplayWriteHierarchicalStreamingSwarm::make()->stream(
        RunContext::from('retry-write', $runId),
    ));

    expect(MemoryWritingFlakyStreamAgent::$attempts)->toBe(2)
        ->and(MemoryWritingFlakyStreamAgent::$writeAttempts)->toBe(1)
        ->and(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write'))->toBe('retry-value')
        ->and(MemoryRecallAgent::$seen)->toContain('retry-write: retry-value');
});

test('a retried static stream worker that throws after writing leaves live memory unchanged', function (string $swarmClass) {
    $runId = 'static-stream-retry-write-throws-'.class_basename($swarmClass);
    seedStaticReplayWriterSnapshot($runId);
    MemoryWritingFlakyStreamAgent::$failAfterWrite = true;

    expect(fn () => iterator_to_array($swarmClass::make()->stream(RunContext::from('retry-write', $runId))))
        ->toThrow(RuntimeException::class, 'memory-write-stream-failed-after-write');

    expect(MemoryWritingFlakyStreamAgent::$attempts)->toBe(2)
        ->and(MemoryWritingFlakyStreamAgent::$writeAttempts)->toBe(1)
        ->and(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write'))->toBeNull();
})->with('static replay writer swarms');

test('a retried static stream worker blocked by a guardrail leaves live memory unchanged', function (string $swarmClass) {
    $runId = 'static-stream-retry-write-guardrail-'.class_basename($swarmClass);
    seedStaticReplayWriterSnapshot($runId);
    config()->set('swarm.guardrails.step', [new BlocksStepWhenIndex(0)]);

    expect(fn () => iterator_to_array($swarmClass::make()->stream(RunContext::from('retry-write', $runId))))
        ->toThrow(GuardrailViolation::class);

    expect(MemoryWritingFlakyStreamAgent::$attempts)->toBe(2)
        ->and(MemoryWritingFlakyStreamAgent::$writeAttempts)->toBe(1)
        ->and(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write'))->toBeNull();
})->with('static replay writer swarms');

test('a retried static stream worker whose step recording fails leaves live memory unchanged', function (string $swarmClass) {
    $runId = 'static-stream-retry-write-recording-'.class_basename($swarmClass);
    seedStaticReplayWriterSnapshot($runId);
    failStaticReplayStepRecording();

    expect(fn () => iterator_to_array($swarmClass::make()->stream(RunContext::from('retry-write', $runId))))
        ->toThrow(RuntimeException::class, 'static-step-recording-failed');

    expect(MemoryWritingFlakyStreamAgent::$attempts)->toBe(2)
        ->and(MemoryWritingFlakyStreamAgent::$writeAttempts)->toBe(1)
        ->and(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write'))->toBeNull();
})->with('static replay writer swarms');

function seedStaticReplayRunHistory(string $runId): void
{
    seedStreamReplayRunHistory($runId, 'ExampleSwarm', 'static_hierarchical');
}

function seedStreamReplayRunHistory(string $runId, string $swarmClass, string $topology): void
{
    $now = now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => $swarmClass,
        'topology' => $topology,
        'status' => 'running',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// ---------------------------------------------------------------------------
// OG1(a) — finally-flush: an abandoned worker stream persists its pending call.
// ---------------------------------------------------------------------------

test('an abandoned static-hierarchical worker stream flushes its in-flight tool call from finally', function () {
    // The worker emits a ToolCall with no following ToolResult; abandon the stream
    // at the tool-call event. The pending call must still reach the snapshot row
    // (result=null) — lost under the pre-fix code where the flush ran inside try
    // after the foreach (which the tear-down never reaches).
    $stream = StreamingStaticHierarchicalUnpairedSwarm::make()->stream('static-unpaired-task');

    $seen = [];
    foreach ($stream as $event) {
        $seen[] = $event;
        if ($event instanceof SwarmToolCall) {
            break; // worker "dies" here, before the (absent) ToolResult.
        }
    }

    expect(collect($seen)->whereInstanceOf(SwarmToolCall::class)->first())->not->toBeNull();
    expect(collect($seen)->whereInstanceOf(SwarmToolResult::class)->first())->toBeNull();

    /** @var SnapshotsMemory $recorder */
    $recorder = app(SnapshotsMemory::class);
    $snapshot = $recorder->find($stream->runId, 0);

    expect($snapshot)->not->toBeNull();
    expect($snapshot->toolCalls)->toHaveCount(1);
    expect($snapshot->toolCalls[0]['name'])->toBe('remember');
    expect($snapshot->toolCalls[0]['result'])->toBeNull();
});

// ---------------------------------------------------------------------------
// OG1(b) — sequential-node resume: replay the frozen value, not live drift.
// ---------------------------------------------------------------------------

test('a static-hierarchical sequential worker resume replays the frozen value, not drifted live memory', function () {
    $runId = 'static-seq-resume-run-id';
    seedStaticReplayRunHistory($runId);

    /** @var SnapshotsMemory $recorder */
    $recorder = app(SnapshotsMemory::class);

    // Seed a frozen snapshot for the worker step (index 0) carrying a value no
    // agent writes — so only the frozen view can surface it.
    $recorder->snapshot($runId, 0, [
        new MemoryEntry(MemoryScope::Run, $runId, 'finding', 'frozen-value'),
    ]);

    // Drift live memory to a value the snapshot must shield against.
    app(SwarmMemory::class)->put(MemoryScope::Run, $runId, 'finding', 'DRIFTED');

    // Resume the same run id: streamAgentEvents() begins the boundary, installs
    // the frozen-view override on the run frame, and the streamed recall reads
    // the frozen value through the same AgentVisibleMemoryView chokepoint (F1).
    $resumed = StreamingStaticHierarchicalRecallSwarm::make()->stream(RunContext::from('recall-task', $runId));
    $resumedEvents = iterator_to_array($resumed);

    $resumedResult = collect($resumedEvents)->whereInstanceOf(SwarmToolResult::class)->first();
    expect($resumedResult->toolResult->result)->toBe('finding: frozen-value');
    expect(collect($resumedEvents)->whereInstanceOf(SwarmTextDelta::class)->first()->delta)
        ->toBe('finding: frozen-value');

    // No frame/override residue after the stream completes.
    expect(ActiveRunContext::current())->toBeNull();
    expect(ActiveRunContext::currentMemory())->toBeNull();
});

// ---------------------------------------------------------------------------
// OG1(b) — concurrent-branch resume under the SYNC driver: each branch
// reconstructs and reads its OWN frozen snapshot. Fork/process branch resume is
// correct-by-construction (each child's begin() is a DB-read reconstruction that
// works in any process), but only the Sync driver is CI-verifiable.
// ---------------------------------------------------------------------------

test('concurrent static-hierarchical branches each resume against their own frozen snapshot under the sync driver', function () {
    // Force synchronous concurrency so the branch callbacks run in-process and
    // their assertions are observable (the fork/process drivers are correct by
    // the same DB-read reconstruction but cannot be asserted across the boundary).
    config()->set('concurrency.default', 'sync');

    $runId = 'static-concurrent-resume-run-id';
    seedStaticReplayRunHistory($runId);

    /** @var SnapshotsMemory $recorder */
    $recorder = app(SnapshotsMemory::class);

    // Two branch step indices (0 and 1), each with its OWN frozen 'finding'.
    $recorder->snapshot($runId, 0, [
        new MemoryEntry(MemoryScope::Run, $runId, 'finding', 'branch-0-frozen'),
    ]);
    $recorder->snapshot($runId, 1, [
        new MemoryEntry(MemoryScope::Run, $runId, 'finding', 'branch-1-frozen'),
    ]);

    // Drift live memory so a live read would observe the wrong value for both.
    app(SwarmMemory::class)->put(MemoryScope::Run, $runId, 'finding', 'DRIFTED');

    $resumed = StreamingStaticHierarchicalConcurrentRecallSwarm::make()
        ->stream(RunContext::from('recall-task', $runId));
    $resumedEvents = iterator_to_array($resumed);

    // Each branch's step_end output carries the value THAT branch recalled — its
    // own frozen snapshot, not the drifted live value nor the other branch's.
    $branchEnds = collect($resumedEvents)
        ->whereInstanceOf(SwarmStepEnd::class)
        ->keyBy(fn (SwarmStepEnd $e): int => $e->stepIndex);

    expect($branchEnds[0]->output)->toBe('finding: branch-0-frozen');
    expect($branchEnds[1]->output)->toBe('finding: branch-1-frozen');

    // Live memory remains the drifted value — the replayed branch reads were
    // served from the frozen views, never the live store.
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'finding'))->toBe('DRIFTED');

    // No frame/override residue after the run completes.
    expect(ActiveRunContext::current())->toBeNull();
    expect(ActiveRunContext::currentMemory())->toBeNull();
});
