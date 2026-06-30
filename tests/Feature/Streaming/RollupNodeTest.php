<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewOrder;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Streaming\View\VoidedEvent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalRollupLoopSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalRollupSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    config()->set('swarm.streaming.replay.enabled', true);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeEditor::fake(['digest-out']);
    FakeWriter::fake(['writer-out']);
});

/**
 * Stream the rollup swarm to completion; return [runId, events].
 *
 * @return array{0: string, 1: list<SwarmStreamEvent>}
 */
function streamRollupRun(): array
{
    $stream = FakeStaticHierarchicalRollupSwarm::make()->stream('kick off');
    $events = iterator_to_array($stream);

    return [$stream->runId, $events];
}

test('a rollup bounds downstream context: the writer reads the digest, never the raw research', function () {
    [, $events] = streamRollupRun();

    // The writer node's step input is composed from with_outputs {digest:
    // rollup_node}. It must carry the digest (the editor's output) and NOT the
    // raw researcher output — that is the operational bound (and R11: with_outputs
    // is the only channel a worker sees prior output through).
    $writerStart = collect($events)
        ->first(fn (SwarmStreamEvent $e) => $e->type() === 'swarm_step_start' && ($e->toArray()['node_id'] ?? null) === 'writer_node');

    expect($writerStart)->not->toBeNull();
    $input = $writerStart->toArray()['input'] ?? '';
    expect($input)->toContain('digest-out')
        ->and($input)->not->toContain('research-out');
});

test('a rollup seals its digested generation: a rolled_up edge and a seal barrier are recorded', function () {
    [$runId] = streamRollupRun();

    $rolledUpEdges = DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->where('void_type', CausalVoidEdgeType::RolledUp->value)
        ->get();

    // Exactly one digested node (researcher_node), so exactly one rolled_up edge,
    // and it carries the digest pointer in its payload.
    expect($rolledUpEdges)->toHaveCount(1);
    $payload = json_decode($rolledUpEdges->first()->payload, true);
    expect($payload['digest_node_id'])->toBe('rollup_node');

    // A mid-run seal barrier exists so #287 can graduate the digested window.
    $barrierCount = DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->where('event_type', 'swarm_causal_seal_barrier')
        ->count();
    expect($barrierCount)->toBeGreaterThanOrEqual(1);
});

test('the display fold suppresses the digested node but keeps the forward chain', function () {
    [$runId] = streamRollupRun();

    $store = app(StreamEventStore::class);
    $clean = CausalLogView::forRun($store, $runId)->fold(ViewOrder::Causal, ViewSupersession::Clean);

    $cleanNodeIds = collect($clean)
        ->map(fn (SwarmStreamEvent $e) => $e->toArray()['node_id'] ?? null)
        ->filter()
        ->unique()
        ->values()
        ->all();

    // researcher_node is digested → suppressed; the rollup and the writer (its
    // forward chain) survive. This is the R6 anti-subtree property end to end.
    expect($cleanNodeIds)->not->toContain('researcher_node')
        ->and($cleanNodeIds)->toContain('rollup_node')
        ->and($cleanNodeIds)->toContain('writer_node');

    // The everything view surfaces the researcher's events as rolled-up, each
    // pointing at the rollup node as its digest.
    $everything = CausalLogView::forRun($store, $runId)->fold(ViewOrder::Causal, ViewSupersession::Everything);
    $rolledUp = collect($everything)
        ->filter(fn ($row) => $row instanceof VoidedEvent && $row->voidType === CausalVoidEdgeType::RolledUp);

    expect($rolledUp)->not->toBeEmpty()
        ->and($rolledUp->every(fn (VoidedEvent $v) => $v->digestNodeId === 'rollup_node'))->toBeTrue()
        ->and($rolledUp->every(fn (VoidedEvent $v) => ($v->event->toArray()['node_id'] ?? null) === 'researcher_node'))->toBeTrue();
});

test('the run still completes with the writer output despite the mid-run seal', function () {
    [, $events] = streamRollupRun();

    $streamEnd = collect($events)->first(fn (SwarmStreamEvent $e) => $e->type() === 'swarm_stream_end');

    expect($streamEnd)->not->toBeNull()
        ->and($streamEnd->toArray()['output'] ?? null)->toBe('writer-out');
});

test('on a non-database driver the rollup still bounds context, and the seal degrades to a no-op (F1)', function () {
    // Swap to the cache driver: events route to the cache store, not the DB
    // causal log. The seal is best-effort and requires the database causal log,
    // so it must degrade to a no-op while the operational prune still applies.
    config()->set('swarm.persistence.driver', 'cache');
    foreach ([StreamEventStore::class, CausalLogStore::class] as $binding) {
        app()->forgetInstance($binding);
    }

    $stream = FakeStaticHierarchicalRollupSwarm::make()->stream('kick off');
    $events = iterator_to_array($stream);

    // The run completes — the swallowed seal never surfaces to the caller.
    $streamEnd = collect($events)->first(fn (SwarmStreamEvent $e) => $e->type() === 'swarm_stream_end');
    expect($streamEnd)->not->toBeNull()
        ->and($streamEnd->toArray()['output'] ?? null)->toBe('writer-out');

    // The operational bound is driver-independent: the writer still reads the
    // digest, never the raw research (the prune runs before the seal attempt).
    $writerStart = collect($events)
        ->first(fn (SwarmStreamEvent $e) => $e->type() === 'swarm_step_start' && ($e->toArray()['node_id'] ?? null) === 'writer_node');
    $input = $writerStart?->toArray()['input'] ?? '';
    expect($input)->toContain('digest-out')
        ->and($input)->not->toContain('research-out');

    // No rolled_up edge was written to the database log — the seal was attempted
    // against a target that lives in the cache store and was swallowed.
    expect(DB::table('swarm_stream_events')->where('void_type', CausalVoidEdgeType::RolledUp->value)->count())->toBe(0);
});

test('a rollup inside a bounded loop digests each iteration without a sealed-target throw (R7)', function () {
    // Two iterations, each producing fresh research the rollup digests in place.
    FakeResearcher::fake(['research-1', 'research-2']);
    FakeEditor::fake(['digest-1', 'digest-2']);
    FakeWriter::fake(['writer-1', 'writer-2']);

    $stream = FakeStaticHierarchicalRollupLoopSwarm::make()->stream('loop');
    $events = iterator_to_array($stream);
    $runId = $stream->runId;

    // The run completed — no SealedCausalWindowException on iteration 2.
    $streamEnd = collect($events)->first(fn (SwarmStreamEvent $e) => $e->type() === 'swarm_stream_end');
    expect($streamEnd)->not->toBeNull();

    // One rolled_up edge per iteration, each targeting a DIFFERENT (fresh)
    // research step-end — never the once-only node-open event.
    $edges = DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->where('void_type', CausalVoidEdgeType::RolledUp->value)
        ->get();

    expect($edges)->toHaveCount(2)
        ->and($edges->pluck('void_target_event_uuid')->unique())->toHaveCount(2);
});

test('a rollup runs as a digester in a synchronous run() — F1 enforced, no seal side effects (OG1)', function () {
    // In sync execution a rollup has no hot causal log to seal, so it executes
    // as a plain digester worker: the digest is produced and read downstream
    // (plan-materialization still forbids referencing the raw research), and the
    // run completes with the writer output. No prune/seal machinery runs.
    $response = FakeStaticHierarchicalRollupSwarm::make()->run('kick off');

    expect($response->output)->toBe('writer-out');
});

test('sealRollup is idempotent and atomic on re-dispatch (R8)', function () {
    // Record a single content event to target, then seal it twice.
    $store = app(CausalLogStore::class);
    $runId = 'run-rollup-idempotent';

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'static_hierarchical',
        'status' => 'running',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $store->record($runId, new SwarmStepEnd(
        id: 'evt-1', runId: $runId, stepIndex: 0, agentClass: FakeResearcher::class,
        agent: 'r', output: 'a', durationMs: 1, metadata: [], timestamp: 1,
    ), 0);

    $first = fn () => $store->sealRollup($runId, ['evt-1'], 'rollup_node', 'rolled up by [rollup_node]');

    $first();
    $first(); // second pass must be a no-op, not a throw or a duplicate.

    $edges = DB::table('swarm_stream_events')->where('run_id', $runId)->where('void_type', 'rolled_up')->count();
    $barriers = DB::table('swarm_stream_events')->where('run_id', $runId)->where('event_type', 'swarm_causal_seal_barrier')->count();

    // Both the rolled_up edge and its barrier are written exactly once: the
    // second pass finds the target already rolled up, emits no edge, and so
    // records no redundant barrier over an empty window (F4).
    expect($edges)->toBe(1)
        ->and($barriers)->toBe(1);
});

test('sealRollup voids a target repeated within one call exactly once (R8 batch skip-set)', function () {
    // The idempotency check is a single batched lookup seeded into a skip-set the
    // loop extends per appended edge — so a target id repeated inside one
    // targetEventIds array is still voided exactly once, matching the prior
    // per-iteration exists() that saw the just-inserted edge.
    $store = app(CausalLogStore::class);
    $runId = 'run-rollup-dup-target';

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'static_hierarchical',
        'status' => 'running',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $store->record($runId, new SwarmStepEnd(
        id: 'evt-1', runId: $runId, stepIndex: 0, agentClass: FakeResearcher::class,
        agent: 'r', output: 'a', durationMs: 1, metadata: [], timestamp: 1,
    ), 0);

    // Same target id twice in one call.
    $store->sealRollup($runId, ['evt-1', 'evt-1'], 'rollup_node', 'rolled up by [rollup_node]');

    $edges = DB::table('swarm_stream_events')->where('run_id', $runId)->where('void_type', 'rolled_up')->count();
    $barriers = DB::table('swarm_stream_events')->where('run_id', $runId)->where('event_type', 'swarm_causal_seal_barrier')->count();

    expect($edges)->toBe(1)
        ->and($barriers)->toBe(1);
});
