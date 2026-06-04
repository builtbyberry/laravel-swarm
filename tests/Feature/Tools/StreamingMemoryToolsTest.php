<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingRecallSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingRememberSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use Illuminate\Support\Facades\Artisan;

/**
 * End-to-end coverage for the Recall and Remember tools inside
 * `$agent->stream(...)`. The fixtures invoke the *real* tool classes mid-stream
 * (not pre-baked tool events), so these tests prove the full path: the tool's
 * memory side-effect lands, its result flows through the stream as a standard
 * `laravel/ai` tool event, the runner pairs call + result into the step
 * snapshot, and persisted replay re-yields the stream byte-identically.
 */
beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

test('a streamed Remember writes to run memory and surfaces a tool result event', function () {
    $stream = StreamingRememberSwarm::make()->stream('streamed-remember-task');

    $events = iterator_to_array($stream);

    // The write actually landed in run memory during the stream.
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $stream->runId, 'finding'))
        ->toBe('streamed-answer');

    // The tool call + result appear in the stream per laravel/ai's contract.
    $toolCall = collect($events)->whereInstanceOf(SwarmToolCall::class)->first();
    $toolResult = collect($events)->whereInstanceOf(SwarmToolResult::class)->first();

    expect($toolCall)->not->toBeNull();
    expect($toolCall->toolCall->name)->toBe('remember');
    expect($toolCall->toolCall->arguments)->toBe(['key' => 'finding', 'value' => 'streamed-answer', 'scope' => 'run']);
    expect($toolResult)->not->toBeNull();
    expect($toolResult->successful)->toBeTrue();
    expect($toolResult->toolResult->result)->toBe('Stored [finding] in run memory.');
});

test('a streamed Recall reads memory an earlier step wrote and streams it back', function () {
    $stream = StreamingRecallSwarm::make()->stream('streamed-recall-task');

    $events = iterator_to_array($stream);

    $toolCall = collect($events)->whereInstanceOf(SwarmToolCall::class)->first();
    $toolResult = collect($events)->whereInstanceOf(SwarmToolResult::class)->first();

    expect($toolCall->toolCall->name)->toBe('recall');
    expect($toolResult->toolResult->result)->toBe('finding: primed-answer');

    // The recalled value crossed the stream boundary as streamed text.
    expect(collect($events)->whereInstanceOf(SwarmTextDelta::class)->first()->delta)
        ->toBe('finding: primed-answer');
});

test('the snapshot captures the streamed Remember tool-call input and output pair', function () {
    $recorder = new RecordingSnapshotsMemory;
    $this->app->instance(SnapshotsMemory::class, $recorder);

    iterator_to_array(StreamingRememberSwarm::make()->stream('snapshot-remember-task'));

    // Exactly one paired append for the single streamed tool call.
    expect($recorder->toolCallAppends)->toHaveCount(1);

    $append = $recorder->toolCallAppends[0]['tool_call'];
    expect($append['name'])->toBe('remember');
    expect($append['arguments'])->toBe(['key' => 'finding', 'value' => 'streamed-answer', 'scope' => 'run']);
    expect($append['result'])->toBe('Stored [finding] in run memory.');
    // The pairing carries the provider call + result ids so replay can re-pair.
    expect($append['id'])->toBe('remember-call-1');
    expect($append['result_id'])->toBe('remember-result-1');
});

test('the snapshot row persists the streamed Remember pair to the database', function () {
    $stream = StreamingRememberSwarm::make()->stream('snapshot-db-task');
    iterator_to_array($stream);

    // The default database-backed recorder is active (driver=database), so the
    // pair must be readable back from swarm_memory_snapshots.tool_calls.
    /** @var SnapshotsMemory $recorder */
    $recorder = app(SnapshotsMemory::class);
    // The streaming agent is the only (and therefore final) step: index 0.
    $snapshot = $recorder->find($stream->runId, 0);

    expect($snapshot)->not->toBeNull();
    expect($snapshot->toolCalls)->toHaveCount(1);
    expect($snapshot->toolCalls[0]['name'])->toBe('remember');
    expect($snapshot->toolCalls[0]['arguments'])->toBe(['key' => 'finding', 'value' => 'streamed-answer', 'scope' => 'run']);
    expect($snapshot->toolCalls[0]['result'])->toBe('Stored [finding] in run memory.');
});

test('persisted replay re-yields a streamed Remember run byte-identically', function () {
    $original = StreamingRememberSwarm::make()
        ->stream('replay-remember-task')
        ->storeForReplay();

    $originalEvents = iterator_to_array($original);

    $replayEvents = iterator_to_array(app(SwarmHistory::class)->replay($original->runId));

    // Byte-identical: the canonical serialization of every event matches.
    $serialize = fn (array $events): array => array_map(
        fn ($event): string => json_encode($event->toArray(), JSON_THROW_ON_ERROR),
        $events,
    );

    expect($serialize($replayEvents))->toBe($serialize($originalEvents));

    // And the tool call + result survived the round trip intact.
    $replayResult = collect($replayEvents)->whereInstanceOf(SwarmToolResult::class)->first();
    expect($replayResult->toolResult->result)->toBe('Stored [finding] in run memory.');
});

test('persisted replay re-yields a streamed Recall run byte-identically', function () {
    $original = StreamingRecallSwarm::make()
        ->stream('replay-recall-task')
        ->storeForReplay();

    $originalEvents = iterator_to_array($original);

    $replayEvents = iterator_to_array(app(SwarmHistory::class)->replay($original->runId));

    $serialize = fn (array $events): array => array_map(
        fn ($event): string => json_encode($event->toArray(), JSON_THROW_ON_ERROR),
        $events,
    );

    expect($serialize($replayEvents))->toBe($serialize($originalEvents));
});
