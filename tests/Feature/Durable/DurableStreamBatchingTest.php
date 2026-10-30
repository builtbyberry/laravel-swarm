<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Compaction\SwarmCompactor;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\CompactSwarmRun;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Persistence\StreamEventPayloadCodec;
use BuiltByBerry\LaravelSwarm\Persistence\TieredStreamEventStore;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableNodeStreamRecorder;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableNodeStreamSink;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeClosed;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Streaming\View\VoidedEvent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\BatchingStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\BatchingFanOutStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableBatchingStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\HierarchicalBatchingStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StaticHierarchicalBatchingStreamingSwarm;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\Data\ToolCall;

/*
 * Batched durable stream-event writes (swarm.durable.stream_batching).
 *
 * The sink-level tests drive DurableNodeStreamSink directly; the durable-path
 * tests run real AdvanceDurableSwarm / AdvanceDurableBranch jobs and observe the
 * stream table at the moment a seal barrier or a branch completion is written.
 */

function configureStreamBatchingRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([
        ContextStore::class,
        ArtifactRepository::class,
        RunHistoryStore::class,
        DurableRunStore::class,
        SwarmRunner::class,
        DurableSwarmManager::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

function enableStreamBatching(int $maxEvents = 50, int $maxBytes = 262144, int $maxAgeMs = 600_000): void
{
    config()->set('swarm.durable.stream_batching', [
        'enabled' => true,
        'max_events' => $maxEvents,
        'max_bytes' => $maxBytes,
        'max_age_ms' => $maxAgeMs,
    ]);
}

/** The stream_events FK parent row a sink-level test writes under. */
function seedStreamBatchingRun(string $runId): void
{
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
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

/**
 * Record every INSERT into the stream-events table as its row count. The table
 * is wrapped by the connection's own grammar, so the match holds on MySQL's
 * backticks as well as SQLite / Postgres double quotes.
 *
 * @return ArrayObject<int, int>
 */
function recordStreamEventInserts(string $table = 'swarm_stream_events'): ArrayObject
{
    $inserts = new ArrayObject;
    $prefix = 'insert into '.DB::connection()->getQueryGrammar()->wrapTable($table).' ';

    DB::listen(function (QueryExecuted $query) use ($inserts, $prefix): void {
        if (str_starts_with($query->sql, $prefix)) {
            // A causal row binds 9 values (DatabaseCausalLogStore::causalRow()).
            $inserts[] = intdiv(count($query->bindings), 9);
        }
    });

    return $inserts;
}

/** @return list<string> */
function streamBatchingUuids(string $runId, ?string $nodeId = null, string $table = 'swarm_stream_events'): array
{
    $query = DB::table($table)->where('run_id', $runId)->whereNotNull('node_id')->orderBy('id');

    if ($nodeId !== null) {
        $query->where('node_id', $nodeId);
    }

    return array_values(array_map('strval', $query->pluck('event_uuid')->all()));
}

function batchingDelta(string $runId, string $id, string $text = 'x'): SwarmTextDelta
{
    return new SwarmTextDelta($id, $runId, 0, 'Agent', $text, 123);
}

/**
 * A batching sink built directly, so a test controls its bounds and clock.
 *
 * @param  (Closure(): float)|null  $clock
 */
function batchingSink(string $runId, string $nodeId = 'step:0', int $epoch = 0, int $maxEvents = 50, int $maxBytes = PHP_INT_MAX, int $maxAgeMs = 600_000, ?Closure $clock = null, ?DatabaseCausalLogStore $store = null): DurableNodeStreamSink
{
    $store ??= app(DatabaseCausalLogStore::class);

    return new DurableNodeStreamSink(
        causalLog: $store,
        runId: $runId,
        nodeId: $nodeId,
        epoch: $epoch,
        ttlSeconds: 3600,
        batching: true,
        maxEvents: $maxEvents,
        maxBytes: $maxBytes,
        maxAgeMs: $maxAgeMs,
        table: $store->tableName(),
        clock: $clock,
    );
}

/** Encoded payload bytes the sink measures for one event. */
function batchingPayloadBytes(string $runId, SwarmStreamEvent $event): int
{
    $row = app(DatabaseCausalLogStore::class)->causalRow($runId, $event->withNodeId('step:0')->withAttemptEpoch(0), 3600);

    return strlen((string) $row['payload']);
}

/**
 * At every seal-barrier INSERT, capture the uuids `$nodeId` has in the table at
 * that moment, keyed by the barrier's id.
 *
 * @return ArrayObject<int, list<string>>
 */
function snapshotNodeAtEachSeal(string $runId, string $nodeId): ArrayObject
{
    $snapshots = new ArrayObject;
    $prefix = 'insert into '.DB::connection()->getQueryGrammar()->wrapTable('swarm_stream_events').' ';

    DB::listen(function (QueryExecuted $query) use ($snapshots, $prefix, $runId, $nodeId): void {
        if (! str_starts_with($query->sql, $prefix) || ! in_array('swarm_causal_seal_barrier', $query->bindings, true)) {
            return;
        }

        $barrierId = (int) DB::table('swarm_stream_events')->where('run_id', $runId)
            ->where('event_type', 'swarm_causal_seal_barrier')->max('id');
        $snapshots[$barrierId] = streamBatchingUuids($runId, $nodeId);
    });

    return $snapshots;
}

/**
 * Assert the node's final rows were all in the table when the first seal barrier
 * after the node's first row was written, and all sit below that barrier.
 *
 * @param  ArrayObject<int, list<string>>  $snapshots
 */
function assertNodeWrittenBeforeItsSeal(string $runId, string $nodeId, ArrayObject $snapshots, int $expectedDeltas): void
{
    $final = streamBatchingUuids($runId, $nodeId);
    $firstId = (int) DB::table('swarm_stream_events')->where('run_id', $runId)->where('node_id', $nodeId)->min('id');
    $maxId = (int) DB::table('swarm_stream_events')->where('run_id', $runId)->where('node_id', $nodeId)->max('id');

    $sealsAfter = array_filter(array_keys($snapshots->getArrayCopy()), fn (int $id): bool => $id > $firstId);
    expect($sealsAfter)->not->toBeEmpty();
    $seal = min($sealsAfter);

    expect(DB::table('swarm_stream_events')->where('run_id', $runId)->where('node_id', $nodeId)
        ->where('event_type', 'swarm_text_delta')->count())->toBe($expectedDeltas)
        ->and($snapshots[$seal])->toBe($final)
        ->and($maxId)->toBeLessThan($seal);
}

/**
 * Per-node event sequence from replay, without storage ids or timestamps.
 *
 * @param  iterable<SwarmStreamEvent>  $events
 * @return list<array{0: string, 1: ?string, 2: ?int, 3: mixed}>
 */
function batchingReplaySequence(iterable $events): array
{
    $sequence = [];

    foreach ($events as $event) {
        $payload = $event->toArray();
        $sequence[] = [$event->type(), $payload['node_id'] ?? null, $event->attemptEpoch, $payload['delta'] ?? null];
    }

    return $sequence;
}

beforeEach(function () {
    configureStreamBatchingRuntime();
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    BatchingStreamEditor::reset();
});

// -----------------------------------------------------------------------------
// The sink: what is written, when.
// -----------------------------------------------------------------------------

test('batching ships off, and with it off every event is its own INSERT, readable at once', function () {
    expect(config('swarm.durable.stream_batching.enabled'))->toBeFalse();

    seedStreamBatchingRun('run-off');
    $inserts = recordStreamEventInserts();
    $sink = app(DurableNodeStreamRecorder::class)->sinkFor('run-off', 'step:0', 0);

    foreach (range(1, 5) as $i) {
        $sink(batchingDelta('run-off', "off-{$i}"));
        expect(streamBatchingUuids('run-off'))->toHaveCount($i);
    }

    $sink->flush();

    expect($inserts->getArrayCopy())->toBe([1, 1, 1, 1, 1]);
});

test('held deltas are written as one multi-row INSERT, in order, with their node and epoch', function () {
    enableStreamBatching();
    seedStreamBatchingRun('run-batch');
    $inserts = recordStreamEventInserts();
    $sink = app(DurableNodeStreamRecorder::class)->sinkFor('run-batch', 'step:3', 2);

    $events = [];
    foreach (range(0, 10) as $i) {
        $events[] = $event = batchingDelta('run-batch', "batch-{$i}", "text-{$i}");
        $sink($event);
    }

    // The first event is written at once; the other ten are held.
    expect(streamBatchingUuids('run-batch'))->toHaveCount(1);

    $sink->flush();

    $rows = DB::table('swarm_stream_events')->where('run_id', 'run-batch')->orderBy('id')->get();
    $replayed = iterator_to_array(app(CausalLogStore::class)->events('run-batch'), false);

    expect($inserts->getArrayCopy())->toBe([1, 10])
        ->and($rows->pluck('event_uuid')->all())->toBe(array_map(fn ($e) => $e->id, $events))
        ->and($rows->pluck('node_id')->unique()->all())->toBe(['step:3'])
        ->and($rows->pluck('attempt_epoch')->map(fn ($e) => (int) $e)->unique()->all())->toBe([2])
        ->and(array_map(fn ($e) => $e->toArray()['delta'], $replayed))->toBe(array_map(fn ($i) => "text-{$i}", range(0, 10)));
});

test('a node attempt\'s first event and every non-delta event are written at once, carrying the deltas held before them', function (Closure $structural) {
    enableStreamBatching();
    seedStreamBatchingRun('run-structural');
    $inserts = recordStreamEventInserts();
    $sink = batchingSink('run-structural');

    $sink(batchingDelta('run-structural', 'first'));
    expect(streamBatchingUuids('run-structural'))->toHaveCount(1);

    foreach (range(1, 3) as $i) {
        $sink(batchingDelta('run-structural', "held-{$i}"));
    }
    expect(streamBatchingUuids('run-structural'))->toHaveCount(1);

    $sink($structural('run-structural'));

    expect(streamBatchingUuids('run-structural'))->toHaveCount(5)
        ->and($inserts->getArrayCopy())->toBe([1, 4]);
})->with([
    'a tool call' => [fn (string $runId) => new SwarmToolCall('tool', $runId, 0, 'Agent', new ToolCall('tool-id', 'tool', []), 123)],
    'a text end' => [fn (string $runId) => new SwarmTextEnd('text-end', $runId, 0, 'Agent', 'message', 123)],
    'a node close' => [fn (string $runId) => new SwarmNodeClosed('node-closed', $runId, null, 123)],
]);

test('reasoning deltas are held like text deltas', function () {
    enableStreamBatching();
    seedStreamBatchingRun('run-reasoning');
    $sink = batchingSink('run-reasoning');

    $sink(batchingDelta('run-reasoning', 'first'));
    $sink(new SwarmReasoningDelta('reasoning-1', 'run-reasoning', 0, 'Agent', 'r', 'thinking', 123, null));

    expect(streamBatchingUuids('run-reasoning'))->toHaveCount(1);

    $sink->flush();

    expect(streamBatchingUuids('run-reasoning'))->toHaveCount(2);
});

test('max_events writes the held deltas once that many are held', function () {
    enableStreamBatching(maxEvents: 3);
    seedStreamBatchingRun('run-count');
    $inserts = recordStreamEventInserts();
    $sink = app(DurableNodeStreamRecorder::class)->sinkFor('run-count', 'step:0', 0);

    foreach (range(0, 6) as $i) {
        $sink(batchingDelta('run-count', "count-{$i}"));
    }

    expect($inserts->getArrayCopy())->toBe([1, 3, 3])
        ->and(streamBatchingUuids('run-count'))->toHaveCount(7);
});

test('max_bytes writes the held deltas before one that would pass it, and writes an oversized event alone', function () {
    enableStreamBatching();
    seedStreamBatchingRun('run-bytes');

    $row = batchingPayloadBytes('run-bytes', batchingDelta('run-bytes', 'probe-000', str_repeat('a', 100)));
    // Strictly between two and three rows: an append-then-check sink would build
    // a three-row INSERT before noticing.
    $budget = (int) floor($row * 2.5);

    $inserts = recordStreamEventInserts();
    $sink = batchingSink('run-bytes', maxBytes: $budget);
    $sink(batchingDelta('run-bytes', 'first-000', str_repeat('a', 100)));

    foreach (range(1, 6) as $i) {
        $sink(batchingDelta('run-bytes', sprintf('bytes-%03d', $i), str_repeat('a', 100)));
    }
    $sink->flush();

    expect($inserts->getArrayCopy())->toBe([1, 2, 2, 2]);

    foreach (DB::table('swarm_stream_events')->where('run_id', 'run-bytes')->orderBy('id')->get()->chunk(2) as $pair) {
        expect($pair->sum(fn ($r) => strlen($r->payload)))->toBeLessThanOrEqual($budget);
    }

    // A single event over the budget is written on its own, at once, after the
    // deltas held before it.
    $inserts->exchangeArray([]);
    $sink(batchingDelta('run-bytes', 'held-small', 'a'));
    $sink(batchingDelta('run-bytes', 'oversized', str_repeat('b', $budget * 2)));

    expect($inserts->getArrayCopy())->toBe([1, 1])
        ->and(array_slice(streamBatchingUuids('run-bytes'), -2))->toBe(['held-small', 'oversized']);
});

test('max_age_ms writes the held deltas when a delta arrives and the oldest held one is that old', function (float $arrival, int $expectedRows) {
    enableStreamBatching();
    seedStreamBatchingRun('run-age');
    $now = 0.0;
    $sink = batchingSink('run-age', maxAgeMs: 250, clock: function () use (&$now): float {
        return $now;
    });

    $sink(batchingDelta('run-age', 'first'));   // written at once

    foreach ([0.0, 100.0, 200.0] as $i => $at) {
        $now = $at;
        $sink(batchingDelta('run-age', "held-{$i}"));
    }
    expect(streamBatchingUuids('run-age'))->toHaveCount(1);

    $now = $arrival;
    $sink(batchingDelta('run-age', 'arriving'));

    expect(streamBatchingUuids('run-age'))->toHaveCount($expectedRows);
})->with([
    'before the oldest is 250 ms old' => [240.0, 1],
    'once the oldest is 250 ms old' => [260.0, 5],
]);

test('while a provider is silent, held deltas wait for its next event (the documented limit)', function () {
    enableStreamBatching();
    seedStreamBatchingRun('run-pause');
    $now = 0.0;
    $sink = batchingSink('run-pause', maxAgeMs: 250, clock: function () use (&$now): float {
        return $now;
    });

    $sink(batchingDelta('run-pause', 'first'));
    $sink(batchingDelta('run-pause', 'held-1'));
    $sink(batchingDelta('run-pause', 'held-2'));

    // The provider goes quiet far past max_age_ms. Nothing runs the sink, so
    // nothing writes: there is no timer.
    $now = 30_000.0;
    expect(streamBatchingUuids('run-pause'))->toBe(['first']);

    $sink(new SwarmTextEnd('text-end', 'run-pause', 0, 'Agent', 'message', 123));

    expect(streamBatchingUuids('run-pause'))->toBe(['first', 'held-1', 'held-2', 'text-end']);
});

test('each sink holds only its own attempt\'s deltas, including two attempts of the same node', function () {
    enableStreamBatching();
    seedStreamBatchingRun('run-a');
    seedStreamBatchingRun('run-b');
    $recorder = app(DurableNodeStreamRecorder::class);

    $sinks = [
        'a0' => $recorder->sinkFor('run-a', 'step:0', 0),
        'a0e1' => $recorder->sinkFor('run-a', 'step:0', 1),
        'a1' => $recorder->sinkFor('run-a', 'step:1', 1),
        'b0' => $recorder->sinkFor('run-b', 'step:0', 0),
    ];
    $runs = ['a0' => 'run-a', 'a0e1' => 'run-a', 'a1' => 'run-a', 'b0' => 'run-b'];

    foreach (range(0, 2) as $i) {
        foreach ($sinks as $key => $sink) {
            $sink(batchingDelta($runs[$key], "{$key}-{$i}"));
        }
    }

    $held = fn (string $key): array => ["{$key}-1", "{$key}-2"];
    $all = fn (): array => array_merge(streamBatchingUuids('run-a'), streamBatchingUuids('run-b'));

    foreach (array_keys($sinks) as $key) {
        expect(array_intersect($held($key), $all()))->toBe([]);
    }

    $sinks['a0']->flush();

    expect(streamBatchingUuids('run-a', 'step:0'))->toBe(['a0-0', 'a0e1-0', 'a0-1', 'a0-2'])
        ->and(array_intersect([...$held('a0e1'), ...$held('a1'), ...$held('b0')], $all()))->toBe([])
        ->and(DB::table('swarm_stream_events')->whereIn('event_uuid', $held('a0'))->pluck('attempt_epoch')->map(fn ($e) => (int) $e)->all())->toBe([0, 0]);

    $sinks['a0e1']->flush();

    expect(DB::table('swarm_stream_events')->whereIn('event_uuid', $held('a0e1'))->pluck('attempt_epoch')->map(fn ($e) => (int) $e)->all())->toBe([1, 1])
        ->and(array_intersect([...$held('a1'), ...$held('b0')], $all()))->toBe([]);
});

test('a sink writes to the table it resolved when it was built', function () {
    Schema::create('swarm_stream_events_other', function ($table): void {
        $table->id();
    });

    enableStreamBatching();
    seedStreamBatchingRun('run-pin');
    $sink = app(DurableNodeStreamRecorder::class)->sinkFor('run-pin', 'step:0', 0);

    $sink(batchingDelta('run-pin', 'first'));
    $sink(batchingDelta('run-pin', 'held'));

    config()->set('swarm.tables.stream_events', 'swarm_stream_events_other');
    $sink->flush();

    expect(streamBatchingUuids('run-pin'))->toBe(['first', 'held'])
        ->and(DB::table('swarm_stream_events_other')->count())->toBe(0);
});

test('a failed batch INSERT propagates and is not written again by a later flush', function () {
    enableStreamBatching();
    seedStreamBatchingRun('run-fail');

    $store = new class(app('db')->connection(), app('config'), app(StreamEventPayloadCodec::class)) extends DatabaseCausalLogStore
    {
        public int $calls = 0;

        public function recordRows(string $table, array $rows): void
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('batch insert failed');
            }

            parent::recordRows($table, $rows);
        }
    };

    $sink = batchingSink('run-fail', store: $store);
    $sink(batchingDelta('run-fail', 'first'));
    $sink(batchingDelta('run-fail', 'held-1'));
    $sink(batchingDelta('run-fail', 'held-2'));

    expect(fn () => $sink->flush())->toThrow(RuntimeException::class, 'batch insert failed');

    $sink->flush();

    expect(streamBatchingUuids('run-fail'))->toBe(['first'])
        ->and($store->calls)->toBe(2);
});

// -----------------------------------------------------------------------------
// Through the durable runtime: written before the seal, retractable after a crash.
// -----------------------------------------------------------------------------

test('a sequential step\'s held deltas are all written before its checkpoint seals it', function () {
    enableStreamBatching();
    BatchingStreamEditor::reset(deltas: 4); // no TextEnd: only the advancer's flush writes them

    $runId = DurableBatchingStreamingSwarm::make()->dispatchDurable('batch-task')->runId;
    $snapshots = snapshotNodeAtEachSeal($runId, 'step:0');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    assertNodeWrittenBeforeItsSeal($runId, 'step:0', $snapshots, 4);
});

test('a hierarchical worker\'s held deltas are all written before its checkpoint seals it', function (string $swarm) {
    enableStreamBatching();
    BatchingStreamEditor::reset(deltas: 4);
    FakeHierarchicalCoordinator::fake([[
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => ['type' => 'worker', 'agent' => BatchingStreamEditor::class, 'prompt' => 'writer-task', 'next' => 'editor_node'],
            'editor_node' => ['type' => 'worker', 'agent' => PlainStreamEditor::class, 'prompt' => 'editor-task', 'next' => 'finish'],
            'finish' => ['type' => 'finish', 'output_from' => 'editor_node'],
        ],
    ]]);

    $runId = $swarm::make()->dispatchDurable('batch-task')->runId;
    $snapshots = snapshotNodeAtEachSeal($runId, 'writer_node');
    $manager = app(DurableSwarmManager::class);

    $guard = 0;
    while (! in_array(($current = $manager->find($runId))['status'], ['completed', 'failed', 'cancelled'], true)) {
        if ($guard++ > 20) {
            throw new RuntimeException('Hierarchical batching run did not converge.');
        }

        (new AdvanceDurableSwarm($runId, (int) $current['next_step_index']))->handle($manager);
    }

    expect($manager->find($runId)['status'])->toBe('completed');
    // The worker's NodeClosed is never held, so it writes the deltas before the
    // seal on its own; this pins that boundary drain on both hierarchy shapes.
    assertNodeWrittenBeforeItsSeal($runId, 'writer_node', $snapshots, 4);
})->with([
    'generated hierarchy' => [HierarchicalBatchingStreamingSwarm::class],
    'static hierarchy' => [StaticHierarchicalBatchingStreamingSwarm::class],
]);

test('a fan-out branch\'s held deltas are all written before it is marked completed, and below the post-join seal', function () {
    enableStreamBatching();
    BatchingStreamEditor::reset(deltas: 4);

    $runId = BatchingFanOutStreamingSwarm::make()->dispatchDurable('fan-out-task')->runId;
    $manager = app(DurableSwarmManager::class);
    $snapshots = snapshotNodeAtEachSeal($runId, 'research_node');

    $atCompletion = null;
    $branchesTable = DB::connection()->getQueryGrammar()->wrapTable('swarm_durable_branches');
    DB::listen(function (QueryExecuted $query) use (&$atCompletion, $branchesTable, $runId): void {
        if ($atCompletion !== null
            || ! str_starts_with($query->sql, 'update '.$branchesTable.' ')
            || ! in_array('completed', $query->bindings, true)) {
            return;
        }

        // The UPDATE has just run in the completion transaction: if it completed
        // the research branch, snapshot that branch's rows at this moment.
        $researchStatus = DB::table('swarm_durable_branches')->where('run_id', $runId)
            ->where('node_id', 'research_node')->value('status');

        if ($researchStatus === 'completed') {
            $atCompletion = streamBatchingUuids($runId, 'research_node');
        }
    });

    $guard = 0;
    while (! in_array(($current = $manager->find($runId))['status'], ['completed', 'failed', 'cancelled'], true)) {
        if ($guard++ > 50) {
            throw new RuntimeException('Fan-out batching run did not converge.');
        }

        if ($current['status'] === 'waiting') {
            foreach (app(DurableRunStore::class)->branchesFor($runId, $current['current_node_id']) as $branch) {
                if (in_array(($branch['status'] ?? null), ['pending', null], true)) {
                    (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
                }
            }

            continue;
        }

        (new AdvanceDurableSwarm($runId, (int) $current['next_step_index']))->handle($manager);
    }

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($atCompletion)->toBe(streamBatchingUuids($runId, 'research_node'));
    assertNodeWrittenBeforeItsSeal($runId, 'research_node', $snapshots, 4);
});

test('a step that crashes holding deltas leaves exactly what it wrote, and resume retracts it', function () {
    enableStreamBatching(maxEvents: 3);
    BatchingStreamEditor::reset(deltas: 2, failuresBeforeSuccess: 1, failAfter: 5);

    $runId = DurableBatchingStreamingSwarm::make()->dispatchDurable('batch-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    // The first delta went out alone and d2-d4 as one batch; d5 was held when the
    // attempt threw, and is gone. Nothing was checkpointed or sealed.
    expect($manager->find($runId)['status'])->toBe('pending')
        ->and(streamBatchingUuids($runId, 'step:0'))->toBe(['batch-a1-d1', 'batch-a1-d2', 'batch-a1-d3', 'batch-a1-d4'])
        ->and(DB::table('swarm_stream_events')->where('run_id', $runId)->where('event_type', 'swarm_causal_seal_barrier')->count())->toBe(0)
        ->and(DB::table('swarm_stream_events')->where('run_id', $runId)->where('node_id', 'step:0')->pluck('attempt_epoch')->map(fn ($e) => (int) $e)->unique()->all())->toBe([0]);

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    $edges = DB::table('swarm_stream_events')->where('run_id', $runId)
        ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)->pluck('void_target_event_uuid')->all();
    expect($edges)->toBe(['batch-a1-d1']);

    $log = app(CausalLogStore::class);
    $clean = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Clean),
        fn ($event) => ($event->toArray()['node_id'] ?? null) === 'step:0' && $event instanceof SwarmTextDelta,
    ));
    $voided = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Everything),
        fn ($event) => $event instanceof VoidedEvent && $event->voidType === CausalVoidEdgeType::NodeReexecuted,
    ));

    expect(array_map(fn ($e) => $e->attemptEpoch, $clean))->toBe([1, 1])
        ->and(array_map(fn ($e) => $e->toArray()['delta'], $clean))->toBe(['batch-2-1 ', 'batch-2-2 '])
        ->and(array_map(fn (VoidedEvent $v) => $v->event->toArray()['delta'], $voided))
        ->toBe(['batch-1-1 ', 'batch-1-2 ', 'batch-1-3 ', 'batch-1-4 ']);
});

test('a step that crashes after only its first event leaves that one row, and resume retracts it', function () {
    enableStreamBatching();
    BatchingStreamEditor::reset(deltas: 2, failuresBeforeSuccess: 1, failAfter: 3);

    $runId = DurableBatchingStreamingSwarm::make()->dispatchDurable('batch-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    expect(streamBatchingUuids($runId, 'step:0'))->toBe(['batch-a1-d1']);

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    expect(DB::table('swarm_stream_events')->where('run_id', $runId)
        ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)->pluck('void_target_event_uuid')->all())
        ->toBe(['batch-a1-d1']);
});

test('a lost lease mid-stream drops the held deltas and writes nothing after it', function () {
    enableStreamBatching(maxEvents: 3);
    BatchingStreamEditor::reset(failuresBeforeSuccess: 1, failAfter: 5, failWith: LostDurableLeaseException::class);

    $runId = DurableBatchingStreamingSwarm::make()->dispatchDurable('batch-task')->runId;

    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));

    expect(streamBatchingUuids($runId, 'step:0'))->toBe(['batch-a1-d1', 'batch-a1-d2', 'batch-a1-d3', 'batch-a1-d4'])
        ->and(DB::table('swarm_stream_events')->where('run_id', $runId)->where('event_type', 'swarm_causal_seal_barrier')->count())->toBe(0);
});

// -----------------------------------------------------------------------------
// The kill switch and compaction are unchanged.
// -----------------------------------------------------------------------------

test('with batching on, the kill switch still runs steps blocking and still seals them', function () {
    enableStreamBatching();
    config()->set('swarm.durable.streaming_enabled', false);

    $runId = DurableBatchingStreamingSwarm::make()->dispatchDurable('batch-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    expect(streamBatchingUuids($runId))->toBe([])
        ->and(BatchingStreamEditor::$attempts)->toBe(0)
        ->and(DB::table('swarm_stream_events')->where('run_id', $runId)->where('event_type', 'swarm_causal_seal_barrier')->count())->toBe(1);
});

test('turning the kill switch off mid-attempt still writes that attempt\'s held deltas; the next step runs blocking and is sealed', function () {
    enableStreamBatching();
    BatchingStreamEditor::reset(deltas: 4);
    BatchingStreamEditor::$midStream = fn () => config()->set('swarm.durable.streaming_enabled', false);

    $runId = DurableBatchingStreamingSwarm::make()->dispatchDurable('batch-task')->runId;
    $manager = app(DurableSwarmManager::class);
    $barriers = fn (): int => DB::table('swarm_stream_events')->where('run_id', $runId)->where('event_type', 'swarm_causal_seal_barrier')->count();

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    expect(streamBatchingUuids($runId, 'step:0'))->toHaveCount(4)
        ->and($barriers())->toBe(1);

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    expect(streamBatchingUuids($runId, 'step:1'))->toBe([])
        ->and($barriers())->toBe(2);
});

test('a crash under batching is still retracted when the kill switch is off on resume', function () {
    enableStreamBatching(maxEvents: 3);
    BatchingStreamEditor::reset(failuresBeforeSuccess: 1, failAfter: 5);

    $runId = DurableBatchingStreamingSwarm::make()->dispatchDurable('batch-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    config()->set('swarm.durable.streaming_enabled', false);

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    expect(DB::table('swarm_stream_events')->where('run_id', $runId)
        ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)->pluck('void_target_event_uuid')->all())
        ->toBe(['batch-a1-d1'])
        ->and(DB::table('swarm_stream_events')->where('run_id', $runId)->where('node_id', 'step:0')->where('attempt_epoch', 1)
            ->where('event_type', 'swarm_text_delta')->count())->toBe(0);
});

test('compaction graduates a batched run exactly as it does an unbatched one', function () {
    $runOnce = function (bool $batching): array {
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        foreach ([SwarmCompactor::class, DatabaseColdArchiveDriver::class, DatabaseCausalLogStore::class, TieredStreamEventStore::class, DurableNodeStreamRecorder::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
        configureStreamBatchingRuntime();
        BatchingStreamEditor::reset(deltas: 6, textEnd: true);
        $batching ? enableStreamBatching(maxEvents: 4) : config()->set('swarm.durable.stream_batching.enabled', false);

        $runId = DurableBatchingStreamingSwarm::make()->dispatchDurable('batch-task')->runId;
        $manager = app(DurableSwarmManager::class);
        foreach ([0, 1, 2] as $step) {
            (new AdvanceDurableSwarm($runId, $step))->handle($manager);
        }

        $lastBarrier = (int) DB::table('swarm_stream_events')->where('run_id', $runId)
            ->where('event_type', 'swarm_causal_seal_barrier')->max('id');
        $belowBarrier = DB::table('swarm_stream_events')->where('run_id', $runId)->where('id', '<', $lastBarrier)
            ->where('event_type', '!=', 'swarm_causal_seal_barrier')->count();
        $before = batchingReplaySequence(app(TieredStreamEventStore::class)->events($runId));

        expect($lastBarrier)->toBeGreaterThan(0)->and($belowBarrier)->toBeGreaterThan(0);

        $guard = 0;
        do {
            (new CompactSwarmRun($runId))->handle(app(SwarmCompactor::class));
        } while (app(DatabaseColdArchiveDriver::class)->basePointer($runId) < $lastBarrier && $guard++ < 5);

        expect(app(DatabaseColdArchiveDriver::class)->basePointer($runId))->toBe($lastBarrier)
            ->and(DB::table('swarm_stream_events')->where('run_id', $runId)->where('id', '<', $lastBarrier)->count())->toBe(0)
            ->and(batchingReplaySequence(app(TieredStreamEventStore::class)->events($runId)))->toBe($before);

        return $before;
    };

    expect($runOnce(true))->toBe($runOnce(false));
});
