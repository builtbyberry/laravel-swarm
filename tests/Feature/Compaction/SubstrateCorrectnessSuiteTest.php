<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Compaction\SwarmCompactor;
use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SealedCausalWindowException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Persistence\TieredStreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalSealBarrier;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewOrder;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Psr\Log\NullLogger;

/**
 * Substrate correctness suite (#290).
 *
 * Encodes the design-gate invariant for the v0.15.0 streaming substrate: a run's
 * durable state reconstructs to exactly ONE consistent value; every read folds the
 * true state or fails loud; no graduated history is lost. These tests cover the
 * exact seams the gate attacked (#282–#289), so the later change review confirms a
 * sound design rather than discovers its flaws.
 *
 * The end-to-end graduation/idempotency mechanics live in BackgroundCompactorTest;
 * this suite layers the cross-tier *correctness* invariants on top:
 *   - folds-equal: compaction is fold-transparent
 *   - crash/race convergence: a partial graduation never loses or duplicates state
 *   - Octane: per-run fold/compaction state is frame-scoped, never leaked
 *   - rotated key: a cold resume read fails loud, never folds null/ciphertext
 *   - supersede/causal-order: sealed history is not retractable; DB id is the truth
 */
beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    config()->set('swarm.persistence.driver', 'database');

    app()->forgetInstance(SwarmCompactor::class);
    app()->forgetInstance(DatabaseColdArchiveDriver::class);
    app()->forgetInstance(DatabaseCausalLogStore::class);
    app()->forgetInstance(TieredStreamEventStore::class);
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Seed the FK parent rows (history + durable) a stream-event run needs.
 */
function seedSubstrateRun(string $runId): void
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

    DB::table('swarm_durable_runs')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'execution_mode' => 'durable',
        'coordination_profile' => 'step_durable',
        'status' => 'running',
        'next_step_index' => 0,
        'current_step_index' => null,
        'total_steps' => 1,
        'route_cursor' => null,
        'route_start_node_id' => null,
        'current_node_id' => null,
        'completed_node_ids' => json_encode([]),
        'timeout_at' => $now->copy()->addHour(),
        'step_timeout_seconds' => 300,
        'attempts' => 1,
        'lease_acquired_at' => null,
        'execution_token' => null,
        'leased_until' => null,
        'recovery_count' => 0,
        'last_recovered_at' => null,
        'pause_requested_at' => null,
        'paused_at' => null,
        'resumed_at' => null,
        'cancel_requested_at' => null,
        'cancelled_at' => null,
        'timed_out_at' => null,
        'wait_reason' => null,
        'waiting_since' => null,
        'wait_timeout_at' => null,
        'last_progress_at' => null,
        'retry_attempt' => 0,
        'next_retry_at' => null,
        'parent_run_id' => null,
        'queue_connection' => null,
        'queue_name' => null,
        'finished_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * Append a text-delta content event to the hot log; returns its DB id.
 */
function recordDelta(string $runId, string $eventId, string $delta): int
{
    app(DatabaseCausalLogStore::class)->record($runId, new SwarmTextDelta(
        id: $eventId,
        runId: $runId,
        stepIndex: 0,
        agentClass: 'ExampleAgent',
        delta: $delta,
        timestamp: SwarmStreamEvent::timestamp(),
    ), 0);

    return (int) DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->where('event_uuid', $eventId)
        ->value('id');
}

function recordStart(string $runId, string $eventId, string $input = 'prompt'): int
{
    app(DatabaseCausalLogStore::class)->record($runId, new SwarmStreamStart(
        id: $eventId,
        runId: $runId,
        swarmClass: 'ExampleSwarm',
        topology: 'sequential',
        input: $input,
        metadata: [],
        timestamp: SwarmStreamEvent::timestamp(),
    ), 0);

    return (int) DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->where('event_uuid', $eventId)
        ->value('id');
}

function recordBarrier(string $runId): int
{
    $uuid = SwarmStreamEvent::newId();

    app(DatabaseCausalLogStore::class)->record($runId, new SwarmCausalSealBarrier(
        id: $uuid,
        runId: $runId,
        timestamp: SwarmStreamEvent::timestamp(),
    ), 0);

    return (int) DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->where('event_uuid', $uuid)
        ->value('id');
}

/**
 * Re-resolve the tiered substrate so it reads fresh base pointers (a fresh
 * worker/request).
 */
function freshTiered(): TieredStreamEventStore
{
    app()->forgetInstance(TieredStreamEventStore::class);
    app()->forgetInstance(DatabaseColdArchiveDriver::class);
    app()->forgetInstance(DatabaseCausalLogStore::class);

    return app(TieredStreamEventStore::class);
}

/**
 * The id-ordered fold of a run as the resume path sees it, via the tiered store.
 *
 * Void-edge bookkeeping events carry their OWN randomly-generated UUID (not a
 * stable content id), so they are excluded by default: two structurally-identical
 * runs would otherwise differ only by that non-deterministic id. Pass
 * `$includeVoidEdges = true` to keep them.
 *
 * @return list<string>
 */
function tieredFoldIds(string $runId, bool $includeVoidEdges = false): array
{
    $view = new CausalLogView(freshTiered()->events($runId));

    $folded = $view->fold(ViewOrder::Causal, ViewSupersession::Clean);

    $ids = [];

    foreach ($folded as $row) {
        $payload = $row->toArray();

        if (! $includeVoidEdges && ($payload['type'] ?? null) === 'swarm_causal_void_edge') {
            continue;
        }

        $ids[] = (string) ($payload['id'] ?? '');
    }

    return $ids;
}

// ─── AC1: Folds-equal — compaction is fold-transparent ───────────────────────

test('compacted log folds identically to the uncompacted log (resume unaffected by compaction)', function () {
    // Two runs with byte-identical event streams. One is compacted, one is not.
    // The resume fold must be indistinguishable.
    $plain = 'run-folds-plain';
    $compacted = 'run-folds-compacted';
    seedSubstrateRun($plain);
    seedSubstrateRun($compacted);

    foreach ([$plain, $compacted] as $runId) {
        recordStart($runId, "{$runId}-s", 'the prompt');
        recordDelta($runId, "{$runId}-d1", 'first');
        recordDelta($runId, "{$runId}-d2", 'second');
        // Supersede d1 — a course-correction the fold must honor identically pre/post compaction.
        app(CausalLogStore::class)->appendVoidEdge($runId, CausalVoidEdgeType::Supersedes, "{$runId}-d1", 'revised');
        recordBarrier($runId);
        recordDelta($runId, "{$runId}-d3", 'third after barrier');
    }

    // Compact only one of them: the sealed prefix [start..barrier) graduates to cold.
    expect(app(SwarmCompactor::class)->compact($compacted))->toBeTrue();

    // The compacted run actually moved data to cold (this is a real, not a no-op, compaction).
    expect(DB::table('swarm_cold_archives')->where('run_id', $compacted)->where('archive_type', 'event')->count())
        ->toBeGreaterThan(0);

    // Normalize the run-id prefix so the two folds are directly comparable.
    // (Void-edge bookkeeping events — whose own id is a random UUID — are excluded
    // by tieredFoldIds(), so what remains is deterministic content.)
    $strip = fn (string $runId, array $ids): array => array_map(
        fn (string $id): string => str_replace("{$runId}-", '', $id),
        $ids,
    );

    $plainFold = $strip($plain, tieredFoldIds($plain));
    $compactedFold = $strip($compacted, tieredFoldIds($compacted));

    // d1 is superseded (absent in the clean fold) in BOTH; d3 (post-barrier, hot) present in BOTH.
    expect($compactedFold)->toBe($plainFold)
        ->and($compactedFold)->toBe(['s', 'd2', 'd3'])
        ->and($compactedFold)->not->toContain('d1');
});

test('the cold snapshot fold equals the live fold of the same window (snapshot ≡ replay)', function () {
    // The compactor seals a fold snapshot of [base, barrier). That snapshot must
    // reconstruct to the same value as folding the raw events of that window —
    // otherwise resume-from-snapshot would diverge from resume-from-replay.
    $runId = 'run-snapshot-equiv';
    seedSubstrateRun($runId);

    recordStart($runId, 'se-s', 'prompt');
    recordDelta($runId, 'se-d1', 'a');
    recordDelta($runId, 'se-d2', 'b');
    $barrierId = recordBarrier($runId);

    // Fold the raw window [0, barrier) BEFORE compaction — the live truth.
    $causalLog = app(DatabaseCausalLogStore::class);
    $liveWindow = [];
    foreach ($causalLog->eventsFrom($runId, 0) as $event) {
        if ($event instanceof SwarmCausalSealBarrier) {
            break;
        }
        $liveWindow[] = $event;
    }
    $liveSnapshot = (new CausalLogView($liveWindow))->snapshot();

    expect(app(SwarmCompactor::class)->compact($runId))->toBeTrue();

    // Decrypt the sealed cold snapshot and compare it to the live fold snapshot.
    /** @var DatabaseColdArchiveDriver $cold */
    $cold = app(DatabaseColdArchiveDriver::class);
    $coldSnapshot = $cold->readSnapshotStrict($runId, app(SwarmPersistenceCipher::class));

    expect($coldSnapshot)->not->toBeNull()
        ->and($coldSnapshot['events'])->toBe($liveSnapshot['events'])
        ->and($coldSnapshot['voids_by_target'])->toBe($liveSnapshot['voids_by_target']);

    // And the barrier never leaked into the snapshot's event list.
    $snapshotTypes = array_column($coldSnapshot['events'], 'type');
    expect($snapshotTypes)->not->toContain('swarm_causal_seal_barrier');
    unset($barrierId);
});

// ─── AC2: Compaction concurrent with live resume ─────────────────────────────

test('a crash AFTER graduate() succeeds but BEFORE reclaim() leaves the run readable with no loss or duplication', function () {
    // The ordering spine is: cold-durable → base-pointer advance → reclaim (hot DELETE).
    // A crash in the reclaim gap leaves the hot rows still present while base_pointer
    // has advanced. The tiered seam (cold owns id < base, hot owns id >= base) must
    // still yield exactly the full set: no gap (loss), no duplicate.
    $runId = 'run-crash-pre-reclaim';
    seedSubstrateRun($runId);

    recordStart($runId, 'cr-s', 'prompt');
    recordDelta($runId, 'cr-d1', 'one');
    recordDelta($runId, 'cr-d2', 'two');
    $barrierId = recordBarrier($runId);
    recordDelta($runId, 'cr-d3', 'three after barrier');

    // Graduate without reclaim — exactly the durable state a crash-before-reclaim leaves.
    /** @var DatabaseColdArchiveDriver $cold */
    $cold = app(DatabaseColdArchiveDriver::class);
    $causalLog = app(DatabaseCausalLogStore::class);
    $cipher = app(SwarmPersistenceCipher::class);

    $window = [];
    foreach ($causalLog->eventsFrom($runId, 0) as $event) {
        if ($event instanceof SwarmCausalSealBarrier) {
            break;
        }
        $window[] = $event;
    }
    $sealed = $cipher->seal(json_encode((new CausalLogView($window))->snapshot(), JSON_THROW_ON_ERROR));

    expect($cold->graduate($runId, 0, $barrierId, $sealed))->toBeTrue();
    // Deliberately DO NOT call reclaim() — simulate the crash.

    // Both cold rows (graduated) AND hot rows (un-reclaimed) now exist for [0, barrier):
    // the window is start + d1 + d2 = 3 content events.
    expect(DB::table('swarm_cold_archives')->where('run_id', $runId)->where('archive_type', 'event')->count())->toBe(3);
    expect(DB::table('swarm_stream_events')->where('run_id', $runId)->where('id', '<', $barrierId)->count())->toBe(3);

    // The tiered seam reads base ONCE and stitches cold [0,base) + hot [base,∞):
    // the un-reclaimed hot rows are below base and excluded, so no duplication.
    $ids = tieredFoldIds($runId);
    expect($ids)->toBe(['cr-s', 'cr-d1', 'cr-d2', 'cr-d3']);

    // A later reclaim is still safe and idempotent — it only completes the spine.
    $cold->reclaim($runId, $barrierId);
    expect(tieredFoldIds($runId))->toBe(['cr-s', 'cr-d1', 'cr-d2', 'cr-d3']);
});

test('a reclaim race (graduate idempotency) cannot wipe cold then find an empty hot log', function () {
    // OG3: lease-expiry race. A second graduate() of an already-graduated window
    // must pre-check base_pointer >= boundary and bail BEFORE touching cold storage,
    // so it can never DELETE cold rows then discover the hot log already reclaimed.
    $runId = 'run-reclaim-race';
    seedSubstrateRun($runId);

    recordStart($runId, 'rr-s', 'prompt');
    recordDelta($runId, 'rr-d1', 'one');
    $barrierId = recordBarrier($runId);

    expect(app(SwarmCompactor::class)->compact($runId))->toBeTrue();
    $cold = app(DatabaseColdArchiveDriver::class);

    // First compactor reclaimed; hot below barrier is gone.
    expect(DB::table('swarm_stream_events')->where('run_id', $runId)->where('id', '<', $barrierId)->count())->toBe(0);
    $coldEventRowsBefore = DB::table('swarm_cold_archives')->where('run_id', $runId)->where('archive_type', 'event')->count();

    // A stale second graduate() of the same window: must return false WITHOUT
    // re-writing or wiping cold rows.
    $sealed = $cold->readSnapshot($runId);
    expect($cold->graduate($runId, 0, $barrierId, (string) $sealed))->toBeFalse();

    // Cold event rows are untouched — no data loss from the racing graduate.
    expect(DB::table('swarm_cold_archives')->where('run_id', $runId)->where('archive_type', 'event')->count())
        ->toBe($coldEventRowsBefore);

    // The fold still reconstructs to exactly one value.
    expect(tieredFoldIds($runId))->toBe(['rr-s', 'rr-d1']);
});

test('restart-fold convergence: folding from cold+hot after a full restart equals the pre-compaction fold', function () {
    // The single-value invariant across a process restart: tear down every
    // substrate singleton (a fresh worker), then fold. The value must not move.
    $runId = 'run-restart-converge';
    seedSubstrateRun($runId);

    recordStart($runId, 'rc-s', 'prompt');
    recordDelta($runId, 'rc-d1', 'one');
    recordDelta($runId, 'rc-d2', 'two');
    app(CausalLogStore::class)->appendVoidEdge($runId, CausalVoidEdgeType::Supersedes, 'rc-d1', 'revised');
    recordBarrier($runId);
    recordDelta($runId, 'rc-d3', 'three');

    $beforeCompaction = tieredFoldIds($runId);

    expect(app(SwarmCompactor::class)->compact($runId))->toBeTrue();

    // Simulate a full restart: forget ALL substrate singletons, then fold afresh.
    $afterRestart = tieredFoldIds($runId);

    expect($afterRestart)->toBe($beforeCompaction)
        ->and($afterRestart)->toBe(['rc-s', 'rc-d2', 'rc-d3']); // rc-d1 superseded
});

// ─── AC3: Octane — frame-scoped fold/compaction state, no cross-run leak ─────

test('the compactor and cold driver carry no mutable per-run instance state (singleton-safe across runs in one worker)', function () {
    // The Octane contract for the compaction substrate is structural, not a reset
    // listener: SwarmCompactor and DatabaseColdArchiveDriver hold NO per-run fields,
    // so the same singleton instance serving run A then run B in one worker cannot
    // leak A's state into B. Assert the contract via reflection.
    foreach ([SwarmCompactor::class, DatabaseColdArchiveDriver::class] as $class) {
        $properties = (new ReflectionClass($class))->getProperties();

        foreach ($properties as $property) {
            // Only collaborators (config, connection, cipher, logger, audit) are
            // allowed — all themselves stateless/singleton. A run-id string, an
            // int cursor, an array buffer, or an untyped/mixed/union field would be
            // a per-run leak surface — require a typed OBJECT collaborator.
            $type = $property->getType();
            $isStatelessCollaborator = $type instanceof ReflectionNamedType && ! $type->isBuiltin();
            expect($isStatelessCollaborator)->toBeTrue(
                "{$class}::\${$property->getName()} must be a typed object collaborator; scalar/array/untyped/mixed/union is a per-run leak surface under Octane."
            );
        }
    }
});

test('two runs compacted by the same singleton compactor in one worker do not leak state', function () {
    // Drive two distinct runs through ONE resolved compactor instance (no
    // forgetInstance between them — exactly the Octane long-lived-worker shape) and
    // assert each folds to its own value, with no cross-contamination.
    $runA = 'run-leak-a';
    $runB = 'run-leak-b';
    seedSubstrateRun($runA);
    seedSubstrateRun($runB);

    recordStart($runA, 'la-s', 'prompt-a');
    recordDelta($runA, 'la-d1', 'a-one');
    recordBarrier($runA);

    recordStart($runB, 'lb-s', 'prompt-b');
    recordDelta($runB, 'lb-d1', 'b-one');
    recordDelta($runB, 'lb-d2', 'b-two');
    recordBarrier($runB);

    /** @var SwarmCompactor $compactor */
    $compactor = app(SwarmCompactor::class); // resolve ONCE — reused across both runs.

    expect($compactor->compact($runA))->toBeTrue();
    expect($compactor->compact($runB))->toBeTrue();

    // Each run graduated only its OWN events — no bleed.
    // Run A window = start + d1 = 2; run B window = start + d1 + d2 = 3.
    expect(DB::table('swarm_cold_archives')->where('run_id', $runA)->where('archive_type', 'event')->count())->toBe(2);
    expect(DB::table('swarm_cold_archives')->where('run_id', $runB)->where('archive_type', 'event')->count())->toBe(3);

    expect(tieredFoldIds($runA))->toBe(['la-s', 'la-d1']);
    expect(tieredFoldIds($runB))->toBe(['lb-s', 'lb-d1', 'lb-d2']);
});

test('the lease is released in finally even when graduation throws (no wedged lease across runs)', function () {
    // The compactor's compact() wraps doCompact() in try/finally: a throw quarantines
    // the run AND releases the lease in finally, so the SAME singleton can immediately
    // serve the next run. A leaked lease here would wedge an unrelated run on the worker.
    $runId = 'run-lease-finally';
    seedSubstrateRun($runId);
    recordStart($runId, 'lf-s', 'prompt');
    recordBarrier($runId);

    // A cold driver whose graduate() throws — forces the catch+finally path.
    $failing = new class extends DatabaseColdArchiveDriver
    {
        public function __construct() {}

        public function graduate(string $runId, int $fromId, int $boundaryId, string $sealedSnapshot): bool
        {
            throw new RuntimeException('boom in graduate');
        }

        public function basePointer(string $runId): int
        {
            return 0;
        }

        public function reclaim(string $runId, int $boundaryId): void {}
    };

    app()->instance(DatabaseColdArchiveDriver::class, $failing);
    app()->forgetInstance(SwarmCompactor::class);

    expect(app(SwarmCompactor::class)->compact($runId))->toBeFalse();

    // finally ran: the lease columns are cleared (token + leased_until both null),
    // not left held by the crashed compaction.
    $row = DB::table('swarm_durable_runs')->where('run_id', $runId)->first();
    expect($row->compaction_token)->toBeNull()
        ->and($row->compaction_leased_until)->toBeNull()
        ->and($row->compaction_quarantined_at)->not->toBeNull();
});

// ─── AC4: Rotated/wrong key on a cold resume read fails loud ──────────────────

test('a cold snapshot resume read under a rotated APP_KEY fails loud and never folds null or ciphertext', function () {
    // #286/#212 convention: operational resume decrypts the cold snapshot via
    // openStrict(). A rotated key must surface a re-dispatchable SwarmException —
    // NEVER silently fold null (treating a non-empty run as empty) or the raw
    // ciphertext (corrupting the fold with garbage).
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(SwarmPersistenceCipher::class);

    $runId = 'run-rotated-key';
    seedSubstrateRun($runId);
    recordStart($runId, 'rk-s', 'prompt');
    recordDelta($runId, 'rk-d1', 'sealed content');
    recordBarrier($runId);

    expect(app(SwarmCompactor::class)->compact($runId))->toBeTrue();

    // The sealed snapshot is sw0:-prefixed ciphertext, not plaintext.
    $sealed = app(DatabaseColdArchiveDriver::class)->readSnapshot($runId);
    expect($sealed)->toStartWith(SwarmPersistenceCipher::PREFIX);

    // Rotate the key: build a cipher whose encrypter holds a DIFFERENT APP_KEY.
    $rotated = new SwarmPersistenceCipher(
        app('config'),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    // openStrict()-backed resume read must throw a re-dispatchable SwarmException.
    expect(fn () => app(DatabaseColdArchiveDriver::class)->readSnapshotStrict($runId, $rotated))
        ->toThrow(SwarmException::class);

    // And critically: it does NOT return null and does NOT return ciphertext.
    try {
        app(DatabaseColdArchiveDriver::class)->readSnapshotStrict($runId, $rotated);
        $this->fail('readSnapshotStrict silently returned on a rotated-key snapshot — must fail loud.');
    } catch (SwarmException $e) {
        expect($e->getMessage())->toContain($runId)
            ->and($e->getMessage())->toContain('Re-dispatch');
    }
});

test('the correct key still decrypts the cold snapshot (the rotated-key failure is key-specific, not a blanket throw)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(SwarmPersistenceCipher::class);

    $runId = 'run-correct-key';
    seedSubstrateRun($runId);
    recordStart($runId, 'ck-s', 'prompt');
    recordBarrier($runId);

    expect(app(SwarmCompactor::class)->compact($runId))->toBeTrue();

    // Same cipher (same key) the compactor sealed with — must decrypt cleanly.
    $snapshot = app(DatabaseColdArchiveDriver::class)
        ->readSnapshotStrict($runId, app(SwarmPersistenceCipher::class));

    expect($snapshot)->not->toBeNull()
        ->and($snapshot)->toHaveKey('events');
});

// ─── AC5: Supersede bounded to unsealed window; DB-sequence is causal truth ───

test('supersede is bounded to the unsealed window: a sealed target is not retractable', function () {
    // After compaction seals [base, barrier), the graduated events carry sealed_at.
    // appendVoidEdge() against any of them must throw SealedCausalWindowException —
    // sealed history is fixed; the only forward motion is appending NEW events.
    $runId = 'run-sealed-immutable';
    seedSubstrateRun($runId);

    recordStart($runId, 'si-s', 'prompt');
    recordDelta($runId, 'si-d1', 'will be sealed');
    $barrierId = recordBarrier($runId);

    // Graduate WITHOUT reclaim — graduate() step 3 sets sealed_at on the hot rows
    // before the (separate) reclaim DELETE, so after a graduate-without-reclaim the
    // real sealed row is still present in hot. This exercises compaction actually
    // sealing the row, not a hand-inserted synthetic one.
    $causalLog = app(DatabaseCausalLogStore::class);
    $cipher = app(SwarmPersistenceCipher::class);
    $cold = app(DatabaseColdArchiveDriver::class);

    $window = [];
    foreach ($causalLog->eventsFrom($runId, 0) as $event) {
        if ($event instanceof SwarmCausalSealBarrier) {
            break;
        }
        $window[] = $event;
    }
    $sealed = $cipher->seal(json_encode((new CausalLogView($window))->snapshot(), JSON_THROW_ON_ERROR));

    expect($cold->graduate($runId, 0, $barrierId, $sealed))->toBeTrue();
    // Deliberately DO NOT reclaim — si-d1 now carries sealed_at but is still in the hot log.

    expect(fn () => app(CausalLogStore::class)->appendVoidEdge(
        $runId,
        CausalVoidEdgeType::Supersedes,
        'si-d1',
        'too late — sealed',
    ))->toThrow(SealedCausalWindowException::class);
});

test('an unsealed target IS still retractable (the bound is sealed-vs-unsealed, not blanket immutability)', function () {
    $runId = 'run-unsealed-retractable';
    seedSubstrateRun($runId);

    recordStart($runId, 'ur-s', 'prompt');
    recordDelta($runId, 'ur-d1', 'draft');

    // No barrier, no compaction → ur-d1 is unsealed → supersede succeeds.
    app(CausalLogStore::class)->appendVoidEdge($runId, CausalVoidEdgeType::Supersedes, 'ur-d1', 'revised');

    $view = CausalLogView::forRun(app(CausalLogStore::class), $runId);
    $clean = array_map(
        fn ($row): string => (string) ($row->toArray()['id'] ?? ''),
        $view->fold(ViewOrder::Causal, ViewSupersession::Clean),
    );

    // ur-d1 is folded out of the clean view; the void-edge bookkeeping event remains.
    expect($clean)->not->toContain('ur-d1')
        ->and($clean)->toContain('ur-s');
});

test('concurrent-branch causal order is the DB sequence, not arrival/timestamp order', function () {
    // Two branches interleave at the application layer, but the causal log assigns a
    // monotonic DB id at append time — THAT is the single source of truth for causal
    // order, even when wall-clock timestamps tie or invert. The fold must follow id.
    $runId = 'run-causal-db-order';
    seedSubstrateRun($runId);

    // Append branch-B's event BEFORE branch-A's, but stamp them with an INVERTED
    // wall clock (B "later", A "earlier") to prove the fold ignores the timestamp.
    $store = app(DatabaseCausalLogStore::class);

    $store->record($runId, new SwarmTextDelta(
        id: 'branch-b-1',
        runId: $runId,
        stepIndex: 1,
        agentClass: 'BranchB',
        delta: 'b',
        timestamp: 9_999, // a "later" wall clock, appended first
    ), 0);

    $store->record($runId, new SwarmTextDelta(
        id: 'branch-a-1',
        runId: $runId,
        stepIndex: 0,
        agentClass: 'BranchA',
        delta: 'a',
        timestamp: 1, // an "earlier" wall clock, appended second
    ), 0);

    $ids = tieredFoldIds($runId);

    // DB append order (b then a) wins — NOT the timestamp order (a before b).
    expect($ids)->toBe(['branch-b-1', 'branch-a-1']);

    // And it survives a graduation: cold preserves the same DB-sequenced order.
    recordBarrier($runId);
    expect(app(SwarmCompactor::class)->compact($runId))->toBeTrue();
    expect(tieredFoldIds($runId))->toBe(['branch-b-1', 'branch-a-1']);
});
