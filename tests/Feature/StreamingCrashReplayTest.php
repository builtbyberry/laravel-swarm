<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\StreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\MemoryReplayCoordinator;
use BuiltByBerry\LaravelSwarm\Memory\NullStreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Memory\StreamStepCheckpoint;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\CountingPrimerAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingPrimerAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\CountingStepGuardrail;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\CountingEchoSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\CountingEchoThreeStepSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRichStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingRecallOnlySwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingRecallSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingRememberSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingUnpairedToolCallSwarm;
use BuiltByBerry\LaravelSwarm\Tools\Recall;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Tools\Request;

/**
 * Seed a minimal swarm_run_histories row so a directly-seeded memory snapshot's
 * run_id FK (swarm_memory_snapshots.run_id -> swarm_run_histories.run_id)
 * resolves. The streaming-resume tests seed snapshots without first running a
 * full crashed attempt, so the history row must exist up front.
 */
function seedCrashReplayRunHistory(string $runId): void
{
    $now = now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
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

/**
 * Crash-replay durability for non-durable streaming runs (issue #192).
 *
 * A streamed sequential run that is abandoned mid-stream (worker crash) must:
 *
 *  1. leave a crash-safe snapshot — every tool call the agent emitted before
 *     the tear-down is persisted, including one still in flight; and
 *  2. replay byte-identically when re-run with the same run id — the resumed
 *     attempt serves the frozen memory view (not whatever live memory drifted
 *     to in the meantime) and rebuilds the tool-call record from scratch.
 *
 * These exercise only the runner's snapshot/replay wiring — the persisted SSE
 * replay store (`storeForReplay()` / `SwarmHistory::replay()`) is a separate,
 * already-covered surface (see StreamingMemoryToolsTest).
 */
beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    // frozen_view is the shipped default; set it explicitly so the test does
    // not depend on the ambient config the suite happens to run under.
    config()->set('swarm.memory.replay_mode', ReplayMode::FrozenView->value);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    // FakeRichStreamingSwarm runs FakeResearcher then FakeWriter before its
    // streamed final agent; stub them so no real provider call is made.
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);

    // The F2 boundary test counts primer re-executions across crash + resume.
    RememberingPrimerAgent::$invocations = 0;

    // #202 multi-step resume tests count provider invocations + side effects.
    CountingPrimerAgent::reset();
    CountingStepGuardrail::$validations = 0;

    // No frame should leak between tests (the override travels on the frame).
    ActiveRunContext::flush();
});

/**
 * Iterate a stream until the predicate first returns true for an event, then
 * abort — modelling a worker that dies at a specific point mid-stream. Dropping
 * the generator reference tears it down and runs its `finally` blocks, exactly
 * as an abandoned streaming worker would.
 *
 * @return array<int, SwarmStreamEvent>
 */
function abandonStreamWhen(StreamableSwarmResponse $stream, callable $predicate): array
{
    $seen = [];

    foreach ($stream as $event) {
        $seen[] = $event;

        if ($predicate($event)) {
            break;
        }
    }

    return $seen;
}

test('an abandoned stream persists an in-flight tool call to the frozen snapshot', function () {
    // The final agent emits a ToolCall with no following ToolResult, then the
    // stream is abandoned before completion. The pending call must still reach
    // the snapshot row (result=null) — losing it would corrupt replay.
    $stream = StreamingUnpairedToolCallSwarm::make()->stream('crash-unpaired-task');

    // Stop the moment the tool-call event surfaces; the matching result never
    // arrives because the worker "dies" here.
    $seen = abandonStreamWhen($stream, fn ($event): bool => $event instanceof SwarmToolCall);

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

test('a stream abandoned mid-run can resume and replay byte-identically from its frozen snapshot', function () {
    $runId = 'crash-replay-run-id';

    // First attempt: primer step writes 'finding' = 'primed-answer' to run
    // memory, then the streamed recall agent reads it. Abandon the stream the
    // moment the final agent's tool call surfaces — the worker "dies" mid-flight
    // with the tool call in flight and the snapshot for step 1 already frozen.
    $crashed = StreamingRecallSwarm::make()->stream(RunContext::from('recall-task', $runId));
    abandonStreamWhen($crashed, fn ($event): bool => $event instanceof SwarmToolCall);

    expect($crashed->runId)->toBe($runId);

    /** @var SnapshotsMemory $recorder */
    $recorder = app(SnapshotsMemory::class);
    $frozen = $recorder->find($runId, 1);
    expect($frozen)->not->toBeNull();

    // Drift live memory AFTER the crash so a replay that read live memory would
    // observe the wrong value. The frozen view must shield the resumed run.
    app(SwarmMemory::class)->put(MemoryScope::Run, $runId, 'finding', 'DRIFTED-after-crash');

    // Resume: re-run the same swarm with the same run id. begin() finds the
    // frozen snapshot, swaps SwarmMemory to the frozen view, resets the partial
    // tool-call record, and the agent re-streams against the frozen memory.
    $resumed = StreamingRecallSwarm::make()->stream(RunContext::from('recall-task', $runId));
    $resumedEvents = iterator_to_array($resumed);

    // Capture the primer count across crash + resume ONLY (before the control run
    // below adds a third primer execution of its own).
    $primerRunsAcrossCrashAndResume = RememberingPrimerAgent::$invocations;

    // The recall read the FROZEN value, not the drifted live value.
    $resumedResult = collect($resumedEvents)->whereInstanceOf(SwarmToolResult::class)->first();
    expect($resumedResult->toolResult->result)->toBe('finding: primed-answer');
    expect(collect($resumedEvents)->whereInstanceOf(SwarmTextDelta::class)->first()->delta)
        ->toBe('finding: primed-answer');

    // Control: a clean run of the same swarm (fresh run id, no crash) for the
    // byte-identity comparison of upstream-originated stream events.
    $control = StreamingRecallSwarm::make()->stream('control-recall-task');
    $controlEvents = iterator_to_array($control);

    // Upstream-originated events (text/reasoning/tool) carry fixture-fixed ids
    // and timestamps, so their serialization is byte-stable across runs once the
    // per-run run_id is normalised out. Swarm-generated framing events
    // (stream_start/step_start/step_end/stream_end) use fresh ids/timestamps and
    // are intentionally excluded from the byte comparison.
    expect(upstreamSignature($resumedEvents, $runId))
        ->toBe(upstreamSignature($controlEvents, $control->runId));

    // The resumed run rebuilt the tool-call snapshot record byte-identically.
    $resumedSnapshot = $recorder->find($runId, 1);
    expect($resumedSnapshot->toolCalls)->toHaveCount(1);
    expect($resumedSnapshot->toolCalls[0]['name'])->toBe('recall');
    expect($resumedSnapshot->toolCalls[0]['result'])->toBe('finding: primed-answer');

    // F2 boundary (#202): idempotent multi-step resume. The non-final primer
    // (index 0) is checkpointed when it completes on the crashed attempt and is
    // SKIPPED on resume — its provider is not re-invoked, so its invocation
    // counter across crash + resume is 1, not 2. The terminal streamed step
    // (index 1) still replays byte-identically from its frozen snapshot. (Here
    // both attempts would write the same value anyway, so the streamed recall is
    // shielded by the frozen snapshot regardless; the F3 test below removes the
    // primer entirely to make the shield assertion non-vacuous.)
    expect($primerRunsAcrossCrashAndResume)->toBe(1);
});

test('the happy-path streamed event sequence is unchanged by the replay wiring', function () {
    // A first, uninterrupted run still produces the full ordered stream and a
    // single paired tool-call snapshot — the replay boundary is invisible on the
    // fresh-execution path.
    $events = iterator_to_array(FakeRichStreamingSwarm::make()->stream('happy-path-task'));

    $types = array_map(fn ($event): string => $event->type(), $events);

    expect($types)->toContain('swarm_stream_start');
    expect($types)->toContain('swarm_text_delta');
    expect($types)->toContain('swarm_tool_call');
    expect($types)->toContain('swarm_tool_result');
    expect($types)->toContain('swarm_stream_end');

    $toolResult = collect($events)->whereInstanceOf(SwarmToolResult::class)->first();
    expect($toolResult->toolResult->result)->toBe(['matches' => 1]);
    // Reasoning events still flow in order around the tool pair.
    expect(collect($events)->whereInstanceOf(SwarmReasoningDelta::class)->first())->not->toBeNull();
    expect(collect($events)->whereInstanceOf(SwarmReasoningEnd::class)->first())->not->toBeNull();
    expect(collect($events)->whereInstanceOf(SwarmTextEnd::class)->first())->not->toBeNull();
});

// ---------------------------------------------------------------------------
// F3 — non-vacuous shield: a SINGLE-step streamed swarm (index 0 IS the streamed
// step, no primer to re-write memory) replays the frozen value, not live drift.
// ---------------------------------------------------------------------------

test('a single-step streamed run replays the frozen value, not drifted live memory', function () {
    $runId = 'single-step-shield-run-id';
    seedCrashReplayRunHistory($runId);

    /** @var SnapshotsMemory $recorder */
    $recorder = app(SnapshotsMemory::class);

    // Seed a frozen snapshot for (runId, 0) carrying a Run-scoped 'finding' that
    // NO agent in this swarm ever writes — so the only way recall can surface it
    // is the frozen view reaching the agent. DefaultPropagationPolicy is Run-only,
    // so the seeded Run-scoped entry is exactly what the policy surfaces.
    $frozen = $recorder->snapshot($runId, 0, [
        new MemoryEntry(MemoryScope::Run, $runId, 'finding', 'frozen-value'),
    ]);
    expect($frozen)->not->toBeNull();
    expect($recorder->find($runId, 0))->not->toBeNull();

    // Pre-assertion: under the active frozen-view override, the real Recall tool
    // returns the seeded value — proving the propagation path from snapshot →
    // ActiveRunContext override → AgentVisibleMemoryView → Recall is wired.
    ActiveRunContext::enter($runId, StreamingRecallOnlySwarm::class, RunContext::from('recall-task', $runId));
    app(MemoryReplayCoordinator::class)
        ->begin(StreamingRecallOnlySwarm::class, $runId, 0);
    $preRecall = app(Recall::class)
        ->handle(new Request(['key' => 'finding', 'scope' => 'run']));
    ActiveRunContext::clearMemoryOverride();
    ActiveRunContext::exit();
    expect($preRecall)->toBe('finding: frozen-value');

    // Drift live memory to a value the snapshot must shield against.
    app(SwarmMemory::class)->put(MemoryScope::Run, $runId, 'finding', 'DRIFTED');

    // Resume the same run id. begin() finds the frozen snapshot for index 0,
    // installs the frozen-view override on the frame, and the streamed recall
    // reads the frozen value — NOT the drifted live value.
    $resumed = StreamingRecallOnlySwarm::make()->stream(RunContext::from('recall-task', $runId));
    $resumedEvents = iterator_to_array($resumed);

    $resumedResult = collect($resumedEvents)->whereInstanceOf(SwarmToolResult::class)->first();
    expect($resumedResult->toolResult->result)->toBe('finding: frozen-value');
    expect(collect($resumedEvents)->whereInstanceOf(SwarmTextDelta::class)->first()->delta)
        ->toBe('finding: frozen-value');

    // Byte-identity vs a clean control run (run_id normalised out). The control
    // is a fresh-execution run (no snapshot) whose live Run memory is seeded with
    // the same value, so its recall produces the same upstream events — proving
    // the replayed run is byte-identical to a clean run that simply read the value.
    $controlRunId = 'control-shield-run-id';
    seedCrashReplayRunHistory($controlRunId);
    app(SwarmMemory::class)->put(MemoryScope::Run, $controlRunId, 'finding', 'frozen-value');
    $control = StreamingRecallOnlySwarm::make()->stream(RunContext::from('recall-task', $controlRunId));
    $controlEvents = iterator_to_array($control);
    expect(upstreamSignature($resumedEvents, $runId))
        ->toBe(upstreamSignature($controlEvents, $controlRunId));

    // The resumed run rebuilt the tool-call snapshot record.
    $resumedSnapshot = $recorder->find($runId, 0);
    expect($resumedSnapshot->toolCalls)->toHaveCount(1);
    expect($resumedSnapshot->toolCalls[0]['name'])->toBe('recall');
    expect($resumedSnapshot->toolCalls[0]['result'])->toBe('finding: frozen-value');
});

// ---------------------------------------------------------------------------
// F1 — write chokepoint: a streamed Remember mid-replay writes to the frozen
// view buffer, never the live store.
// ---------------------------------------------------------------------------

test('a streamed Remember mid-replay writes to the frozen buffer, not live memory', function () {
    $runId = 'write-path-replay-run-id';
    seedCrashReplayRunHistory($runId);

    /** @var SnapshotsMemory $recorder */
    $recorder = app(SnapshotsMemory::class);

    // Seed a frozen snapshot so the resumed single-step run takes the replay path.
    $recorder->snapshot($runId, 0, [
        new MemoryEntry(MemoryScope::Run, $runId, 'finding', 'frozen-seed'),
    ]);

    // Drift live memory so we can prove the streamed write did not land here.
    app(SwarmMemory::class)->put(MemoryScope::Run, $runId, 'finding', 'live-untouched');

    // Resume: the StreamingRememberAgent writes finding='streamed-answer' via the
    // real Remember tool mid-stream. Under the frozen-view override that write is
    // buffered in the ReplaySwarmMemory, never the live DefaultSwarmMemory.
    $resumed = StreamingRememberSwarm::make()->stream(RunContext::from('remember-task', $runId));
    iterator_to_array($resumed);

    // The live store is the real DefaultSwarmMemory and was NOT touched by the
    // replayed write — it still holds the drifted value.
    expect(app(SwarmMemory::class))->toBeInstanceOf(DefaultSwarmMemory::class);
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'finding'))->toBe('live-untouched');

    // No frame or override residue after the stream completes.
    expect(ActiveRunContext::current())->toBeNull();
    expect(ActiveRunContext::currentMemory())->toBeNull();
});

// ---------------------------------------------------------------------------
// F1 — Octane/concurrency: two in-process streams sharing one container, with
// their generators manually interleaved, each replay against their OWN frozen
// snapshot with no cross-run bleed and no global residue. Interleaving two
// generators against one container is the in-process model of Octane fiber /
// request pooling. This test FAILS under the old process-global SwarmMemory
// rebind: the second begin() would clobber the first's container binding (and
// restore the wrong original), so run A would read run B's frozen value.
// ---------------------------------------------------------------------------

test('two concurrent in-process streams each replay their own frozen snapshot with no global residue', function () {
    $runA = 'concurrent-run-A';
    $runB = 'concurrent-run-B';
    seedCrashReplayRunHistory($runA);
    seedCrashReplayRunHistory($runB);

    /** @var SnapshotsMemory $recorder */
    $recorder = app(SnapshotsMemory::class);

    // Two distinct frozen snapshots for two distinct runs, same key 'finding'.
    $recorder->snapshot($runA, 0, [new MemoryEntry(MemoryScope::Run, $runA, 'finding', 'A-value')]);
    $recorder->snapshot($runB, 0, [new MemoryEntry(MemoryScope::Run, $runB, 'finding', 'B-value')]);

    // Drift live memory for both so a live read would observe the wrong value.
    app(SwarmMemory::class)->put(MemoryScope::Run, $runA, 'finding', 'A-DRIFTED');
    app(SwarmMemory::class)->put(MemoryScope::Run, $runB, 'finding', 'B-DRIFTED');

    // Get both streams' generators without advancing them yet. Manual generator
    // control (rewind/current/next) lets us suspend A mid-stream, run B fully,
    // then resume A — foreach cannot resume a generator after a break.
    $genA = StreamingRecallOnlySwarm::make()->stream(RunContext::from('recall-task', $runA))->getIterator();
    $genB = StreamingRecallOnlySwarm::make()->stream(RunContext::from('recall-task', $runB))->getIterator();

    // Advance A past its recall tool-call (the recall already ran by the time the
    // SwarmToolResult surfaces), but do NOT finish A — its frame stays live.
    $aResult = null;
    $genA->rewind();
    while ($genA->valid()) {
        $event = $genA->current();
        if ($event instanceof SwarmToolResult) {
            $aResult = $event->toolResult->result;
            break; // A is now suspended mid-stream, frame still on the stack.
        }
        $genA->next();
    }

    // Now drive B fully to completion while A is still suspended mid-stream.
    $bResult = null;
    $genB->rewind();
    while ($genB->valid()) {
        $event = $genB->current();
        if ($event instanceof SwarmToolResult) {
            $bResult = $event->toolResult->result;
        }
        $genB->next();
    }

    // Resume A to completion.
    while ($genA->valid()) {
        $genA->next();
    }

    // Each stream recalled ITS OWN frozen value — no cross-run bleed.
    expect($aResult)->toBe('finding: A-value');
    expect($bResult)->toBe('finding: B-value');

    // After both finish, the container binding is the unmodified live store and
    // no frame leaked — there is no global residue from either replay.
    expect(app(SwarmMemory::class))->toBeInstanceOf(DefaultSwarmMemory::class);
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $runA, 'finding'))->toBe('A-DRIFTED');
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $runB, 'finding'))->toBe('B-DRIFTED');
    expect(ActiveRunContext::current())->toBeNull();
    expect(ActiveRunContext::currentMemory())->toBeNull();
});

// ---------------------------------------------------------------------------
// #202 — idempotent multi-step resume: a completed NON-final streamed step is
// skipped on resume (provider not re-invoked, tool side effects not re-fired)
// and its output is rehydrated from a per-step checkpoint, so the downstream
// prompt — and therefore the final streamed step — is byte-identical.
// ---------------------------------------------------------------------------

test('#202 a completed non-final step is skipped on resume and its output is rehydrated, not recomputed', function () {
    $runId = 'multistep-rehydrate-run-id';

    // First attempt: the counter-dependent primer (step 0) runs once → output
    // 'primed-1'; the echo final step streams that value. Abandon mid-final-
    // stream at the first text delta — step 0 has completed (its checkpoint is
    // recorded) and the final step's snapshot is frozen.
    $crashed = CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId));
    abandonStreamWhen($crashed, fn ($event): bool => $event instanceof SwarmTextDelta);

    expect(CountingPrimerAgent::$invocations)->toBe(1);

    // A COMPLETED checkpoint exists for the non-final step, carrying the raw
    // output fed downstream.
    /** @var StreamStepCheckpointStore $checkpoints */
    $checkpoints = app(StreamStepCheckpointStore::class);
    $checkpoint = $checkpoints->find($runId, 0);
    expect($checkpoint)->not->toBeNull();
    expect($checkpoint->output)->toBe('primed-1');

    // Resume with the same run id.
    $resumed = CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId));
    $resumedEvents = iterator_to_array($resumed);

    // The non-final primer was NOT re-invoked and its (external) side effect did
    // NOT re-fire — both counters are still 1 across crash + resume.
    expect(CountingPrimerAgent::$invocations)->toBe(1);
    expect(CountingPrimerAgent::$sideEffects)->toBe(1);

    // The echoed downstream prompt is the REHYDRATED 'primed-1'. A run that
    // re-executed the primer would have produced 'primed-2'.
    $delta = collect($resumedEvents)->whereInstanceOf(SwarmTextDelta::class)->first();
    expect($delta->delta)->toBe('primed-1');
});

test('#202 a multi-step chain skips every completed non-final step on resume', function () {
    $runId = 'multistep-chain-run-id';

    // Two non-final primers feed the echo final step. On the crashed attempt
    // both run once (shared counter → 2): step 0 → 'primed-1', step 1 → 'primed-2'.
    $crashed = CountingEchoThreeStepSwarm::make()->stream(RunContext::from('echo-task', $runId));
    abandonStreamWhen($crashed, fn ($event): bool => $event instanceof SwarmTextDelta);

    expect(CountingPrimerAgent::$invocations)->toBe(2);

    /** @var StreamStepCheckpointStore $checkpoints */
    $checkpoints = app(StreamStepCheckpointStore::class);
    expect($checkpoints->find($runId, 0)?->output)->toBe('primed-1');
    expect($checkpoints->find($runId, 1)?->output)->toBe('primed-2');

    $resumed = CountingEchoThreeStepSwarm::make()->stream(RunContext::from('echo-task', $runId));
    $resumedEvents = iterator_to_array($resumed);

    // Both non-final steps skipped on resume — the shared counter stays at 2,
    // not 4.
    expect(CountingPrimerAgent::$invocations)->toBe(2);

    // The final echo streamed the rehydrated middle-step output 'primed-2'.
    $delta = collect($resumedEvents)->whereInstanceOf(SwarmTextDelta::class)->first();
    expect($delta->delta)->toBe('primed-2');
});

test('#202 a non-final step crashed before completion has no checkpoint and re-executes on resume', function () {
    $runId = 'multistep-precrash-run-id';

    // Abandon at the FIRST step-start, before the primer's prompt() runs, so
    // step 0 never completes and no checkpoint is recorded.
    $crashed = CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId));
    abandonStreamWhen($crashed, fn ($event): bool => $event instanceof SwarmStepStart && $event->stepIndex === 0);

    expect(CountingPrimerAgent::$invocations)->toBe(0);

    /** @var StreamStepCheckpointStore $checkpoints */
    $checkpoints = app(StreamStepCheckpointStore::class);
    expect($checkpoints->find($runId, 0))->toBeNull();

    // Resume: with no completed checkpoint, step 0 executes (counter → 1).
    iterator_to_array(CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId)));

    expect(CountingPrimerAgent::$invocations)->toBe(1);
});

test('#202 fresh_execution mode leaves non-final steps re-executing and writes no checkpoints', function () {
    config()->set('swarm.memory.replay_mode', ReplayMode::FreshExecution->value);
    $runId = 'multistep-fresh-exec-run-id';

    $crashed = CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId));
    abandonStreamWhen($crashed, fn ($event): bool => $event instanceof SwarmTextDelta);

    iterator_to_array(CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId)));

    // Re-executed on resume (pre-#202 behaviour): the primer ran twice.
    expect(CountingPrimerAgent::$invocations)->toBe(2);

    // No checkpoints were written in fresh_execution mode.
    expect(DB::table('swarm_stream_step_checkpoints')->count())->toBe(0);
});

test('#202 resuming a skipped step does not duplicate its persisted artifacts', function () {
    $runId = 'multistep-artifact-run-id';

    $crashed = CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId));
    abandonStreamWhen($crashed, fn ($event): bool => $event instanceof SwarmTextDelta);

    $artifactCount = fn (): int => DB::table('swarm_artifacts')
        ->where('run_id', $runId)
        ->where('step_agent_class', CountingPrimerAgent::class)
        ->count();

    $afterCrash = $artifactCount();
    expect($afterCrash)->toBe(1);

    iterator_to_array(CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId)));

    // The skip path stores no new artifact row — the step-0 artifact is already
    // durable from the crashed attempt. Without storeArtifacts:false this would
    // be 2.
    expect($artifactCount())->toBe($afterCrash);
});

test('#202 the checkpoint store is the no-op binding under the cache driver', function () {
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(StreamStepCheckpointStore::class);

    expect(app(StreamStepCheckpointStore::class))->toBeInstanceOf(NullStreamStepCheckpointStore::class);
    expect(app(StreamStepCheckpointStore::class)->find('any-run', 0))->toBeNull();
});

test('#202 the database checkpoint store treats a null-output row as absent', function () {
    $runId = 'marker-semantics-run-id';
    seedCrashReplayRunHistory($runId);

    /** @var StreamStepCheckpointStore $checkpoints */
    $checkpoints = app(StreamStepCheckpointStore::class);

    // A recorded (completed) checkpoint round-trips — including an empty-string
    // output, which is non-null and therefore a valid completed step.
    $checkpoints->record($runId, 0, '', ['prompt_tokens' => 3]);
    $found = $checkpoints->find($runId, 0);
    expect($found)->not->toBeNull();
    expect($found->output)->toBe('');
    expect($found->usage)->toBe(['prompt_tokens' => 3]);

    // A row whose output column is NULL reads as absent so the step re-executes.
    DB::table('swarm_stream_step_checkpoints')->insert([
        'run_id' => $runId,
        'step_index' => 1,
        'output' => null,
        'usage' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($checkpoints->find($runId, 1))->toBeNull();
});

test('#202 a resumed run rehydrates the non-final step usage, not just its output', function () {
    $runId = 'multistep-usage-run-id';

    // The primer reports a non-zero usage; the echo final step adds its own. On
    // the original run the merged total is primer(7/11) + echo(1/1).
    $crashed = CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId));
    abandonStreamWhen($crashed, fn ($event): bool => $event instanceof SwarmTextDelta);

    // Resume: the skipped primer's usage must be re-merged from the checkpoint.
    $resumed = CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId));
    $resumedEnd = collect(iterator_to_array($resumed))->whereInstanceOf(SwarmStreamEnd::class)->first();

    // Control: a clean run of the same swarm carries the full merged usage.
    $control = CountingEchoSwarm::make()->stream('control-usage-task');
    $controlEnd = collect(iterator_to_array($control))->whereInstanceOf(SwarmStreamEnd::class)->first();

    // The resumed run's usage equals the control's — proving the non-final
    // step's usage was rehydrated, not dropped. (If usage rehydration were
    // broken the resumed total would be missing the primer's 7/11.)
    expect($resumedEnd->usage)->toBe($controlEnd->usage);
    // And it actually includes the primer's contribution (non-vacuous).
    expect($resumedEnd->usage['prompt_tokens'] ?? 0)->toBeGreaterThanOrEqual(7);
});

test('#202 the skip path re-runs step guardrails on the rehydrated output', function () {
    config()->set('swarm.guardrails.step', [CountingStepGuardrail::class]);
    $this->app->bind(CountingStepGuardrail::class, fn () => new CountingStepGuardrail(0));

    $runId = 'multistep-guardrail-run-id';

    // Original run: the step-0 guardrail validates once before the crash.
    $crashed = CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId));
    abandonStreamWhen($crashed, fn ($event): bool => $event instanceof SwarmTextDelta);
    expect(CountingStepGuardrail::$validations)->toBe(1);

    // Resume: step 0 is skipped (provider not re-invoked) BUT its guardrail must
    // still run against the rehydrated output — so the counter reaches 2.
    iterator_to_array(CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId)));

    expect(CountingStepGuardrail::$validations)->toBe(2);
    // The provider itself was not re-invoked on resume.
    expect(CountingPrimerAgent::$invocations)->toBe(1);
});

test('#202 checkpoints are isolated by (run_id, step_index) with no cross-run bleed', function () {
    // The store is a stateless singleton — its only instance field is the
    // immutable cached table-exists flag — so two concurrent in-process runs
    // (the Octane fiber model) touch only their own disjoint rows. A direct
    // store round-trip is the faithful proof of that isolation.
    seedCrashReplayRunHistory('iso-run-A');
    seedCrashReplayRunHistory('iso-run-B');

    /** @var StreamStepCheckpointStore $checkpoints */
    $checkpoints = app(StreamStepCheckpointStore::class);

    $checkpoints->record('iso-run-A', 0, 'A-out', []);
    $checkpoints->record('iso-run-B', 0, 'B-out', []);
    $checkpoints->record('iso-run-A', 1, 'A-out-1', []);

    expect($checkpoints->find('iso-run-A', 0)?->output)->toBe('A-out');
    expect($checkpoints->find('iso-run-B', 0)?->output)->toBe('B-out');
    expect($checkpoints->find('iso-run-A', 1)?->output)->toBe('A-out-1');
    expect($checkpoints->find('iso-run-B', 1))->toBeNull();
});

test('#202 a checkpoint-store write failure does not abort the completed step stream', function () {
    // A best-effort checkpoint: if record() throws after the step is done, the
    // stream must still complete (the step already ran + side-effected) and a
    // warning is logged. The store double throws on every record().
    Log::spy();

    app()->instance(StreamStepCheckpointStore::class, new class implements StreamStepCheckpointStore
    {
        public function record(string $runId, int $stepIndex, string $output, array $usage): void
        {
            throw new RuntimeException('checkpoint write boom');
        }

        public function find(string $runId, int $stepIndex): ?StreamStepCheckpoint
        {
            return null;
        }
    });

    $events = iterator_to_array(CountingEchoSwarm::make()->stream(RunContext::from('echo-task', 'failsoft-run-id')));

    // The stream got past the throwing non-final record() to the final echo step.
    expect(collect($events)->whereInstanceOf(SwarmTextDelta::class)->first()->delta)->toBe('primed-1');
    expect(collect($events)->last())->toBeInstanceOf(SwarmStreamEnd::class);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

test('#202 multi-step resume degrades to re-execution when the checkpoint table is absent', function () {
    // Deploy-before-migrate window: database driver, but the checkpoint table
    // has not been created. The store precheck must no-op record()/find()
    // instead of throwing mid-stream — so the run works and resume simply
    // re-executes the non-final step (pre-#202 behaviour).
    Schema::drop('swarm_stream_step_checkpoints');

    $runId = 'multistep-no-table-run-id';

    $crashed = CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId));
    abandonStreamWhen($crashed, fn ($event): bool => $event instanceof SwarmTextDelta);

    iterator_to_array(CountingEchoSwarm::make()->stream(RunContext::from('echo-task', $runId)));

    // No checkpoint table → no skip → the primer re-executed on resume.
    expect(CountingPrimerAgent::$invocations)->toBe(2);
});

/**
 * Serialize only the upstream-originated stream events (text/reasoning/tool),
 * with the per-run run_id normalised out, so two runs of a deterministic agent
 * fixture can be compared byte-for-byte.
 *
 * @param  array<int, SwarmStreamEvent>  $events
 * @return array<int, string>
 */
function upstreamSignature(array $events, string $runId): array
{
    return collect($events)
        ->filter(fn ($event): bool => in_array(
            $event::class,
            [SwarmTextDelta::class, SwarmTextEnd::class, SwarmReasoningDelta::class, SwarmReasoningEnd::class, SwarmToolCall::class, SwarmToolResult::class],
            true,
        ))
        ->map(function ($event) use ($runId): string {
            $payload = $event->toArray();
            $payload['run_id'] = str_replace($runId, '{run_id}', (string) ($payload['run_id'] ?? ''));

            return json_encode($payload, JSON_THROW_ON_ERROR);
        })
        ->values()
        ->all();
}
