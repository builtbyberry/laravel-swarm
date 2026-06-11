<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\MemoryReplayCoordinator;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingPrimerAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRichStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingRecallOnlySwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingRecallSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingRememberSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingUnpairedToolCallSwarm;
use BuiltByBerry\LaravelSwarm\Tools\Recall;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

    // F2 boundary (#202): only the TERMINAL streamed step (index 1) replays from
    // its frozen snapshot. The non-final primer (index 0) RE-EXECUTES on resume —
    // it ran once on the crashed attempt and once again on the resumed run, so its
    // invocation counter across crash + resume is 2, not 1. (Here both runs write
    // the same value, so the streamed recall is shielded by the frozen snapshot
    // regardless; the F3 test below removes the primer entirely to make the shield
    // assertion non-vacuous.)
    expect($primerRunsAcrossCrashAndResume)->toBe(2);
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
