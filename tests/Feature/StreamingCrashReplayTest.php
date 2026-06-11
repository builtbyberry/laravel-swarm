<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRichStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingRecallSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingUnpairedToolCallSwarm;
use Illuminate\Support\Facades\Artisan;

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
