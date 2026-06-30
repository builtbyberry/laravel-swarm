<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SealedCausalWindowException;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Streaming\View\VoidedEvent;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\Facades\DB;

/**
 * Process-concurrency coverage for the durable parallel-branch void/seal contention
 * at the BRANCH-SCENARIO level (#312 review F1).
 *
 * The primitive seal-fence test (CausalLogSealFenceConcurrencyTest, #282) proves
 * appendVoidEdge()'s lockForUpdate closes the TOCTOU window for a void racing a seal
 * of the SAME target. This test exercises the higher-level durable-branch invariant
 * that #312 introduces: a crashed branch's resume-time {@see CausalLogStore::voidNodeAttempt()}
 * (retracting that branch's prior attempt) racing a CONCURRENT sibling branch's
 * post-join seal (the #287 compactor sealing the sibling's committed, below-barrier
 * events — mirrored here by the same lockForUpdate + sealed_at UPDATE the cold-archive
 * driver's graduate() performs).
 *
 * The seal-on-join invariant (gate H2): the post-join seal only seals the committed
 * sibling's events; the crashed branch's events sit ABOVE the join barrier, unsealed,
 * so the void always lands. The three properties asserted, for every target:
 *   (a) ONLY the crashed branch's prior attempt is voided.
 *   (b) the sibling's committed events are untouched and present, un-voided, in the
 *       clean fold.
 *   (c) NO SealedCausalWindowException is thrown — the void never hits a sealed target.
 *
 * Per the project's concurrency-test philosophy (mirrors CausalLogSealFenceConcurrencyTest
 * and DurableRunStateConcurrencyTest): the multi-process driver gives no interleaving
 * guarantee, so the deterministic proof of the void/seal ordering lives in the
 * Feature test (DurableParallelBranchStreamingTest — "one branch crashes + resumes
 * while the sibling commits"). This test's complementary job is to run the void and
 * the seal head-on against the SAME run on a real engine and assert every outcome is
 * one of the legal serial outcomes — never an inconsistent one, and never a thrown
 * SealedCausalWindowException.
 */
// Tagged `skip-locked-real-db` so the CI lane excludes it under `--fail-on-skipped`:
// it needs a shared MySQL/Postgres connection reachable from the child processes the
// concurrency driver spawns; in-memory SQLite is per-process and unreachable.
pest()->group('process-concurrency', 'skip-locked-real-db');

/**
 * True if the configured testing connection is a real, shared DB engine the process
 * concurrency driver can reach from child processes. SQLite (the testbench default)
 * is excluded: :memory: is per-process and a file SQLite has no row-lock semantics
 * worth racing. sqlsrv is excluded to mirror the sibling concurrency lanes.
 */
function durableBranchVoidSealDriverSupported(): bool
{
    return ! in_array(
        DB::connection()->getDriverName(),
        ['sqlite', 'sqlsrv'],
        true,
    );
}

/**
 * Void worker: for each run id in order, retract the crashed branch's prior attempt
 * via the real {@see CausalLogStore::voidNodeAttempt()} — the exact call the durable
 * branch advancer makes on resume. A free function so its scope class is null and it
 * serializes cleanly to the child PHP process (no Pest runtime, no $this). See
 * CausalLogSealFenceConcurrencyTest for the full serialization rationale.
 *
 * @param  array<int, string>  $runIds
 */
function durableBranchVoidWorker(array $runIds, string $crashedNodeId, int $crashedEpoch): Closure
{
    return static function () use ($runIds, $crashedNodeId, $crashedEpoch): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $store = app(CausalLogStore::class);
        $outcomes = [];

        foreach ($runIds as $runId) {
            try {
                // Retracts the crashed branch's prior attempt — keyed on (crashed node
                // id, crashed epoch), never the sibling. Throws SealedCausalWindowException
                // only if the target were sealed (the invariant says it never is).
                $store->voidNodeAttempt($runId, $crashedNodeId, $crashedEpoch, 'durable node re-executed on resume');
                $outcomes[$runId] = 'voided';
            } catch (SealedCausalWindowException) {
                // The invariant breach this test exists to catch: the void hit a sealed
                // target. Never legal for a crashed (above-barrier) branch attempt.
                $outcomes[$runId] = 'rejected-sealed';
            } catch (Throwable $e) {
                // Lost the row contention as the deadlock victim: rolled back without
                // a void-edge. A legal "void lost, retry on next resume" outcome.
                $outcomes[$runId] = 'aborted:'.$e::class;
            }
        }

        return $outcomes;
    };
}

/**
 * Seal worker: for each run id in order, seal the COMMITTED sibling branch's events —
 * the post-join compaction seal. Mirrors DatabaseColdArchiveDriver::graduate() step 3:
 * the same lockForUpdate + sealed_at UPDATE, scoped to the sibling's node id so it
 * stands for "seal only the below-join-barrier committed window". A free function for
 * clean child-process serialization.
 *
 * @param  array<int, string>  $runIds
 */
function durableBranchSealWorker(array $runIds, string $committedNodeId): Closure
{
    return static function () use ($runIds, $committedNodeId): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $table = (string) config('swarm.tables.stream_events', 'swarm_stream_events');
        $outcomes = [];

        foreach ($runIds as $runId) {
            try {
                DB::transaction(function () use ($table, $runId, $committedNodeId): void {
                    // Lock the committed sibling's rows (the same lock appendVoidEdge
                    // takes on its target), then stamp sealed_at — the post-join seal.
                    DB::table($table)
                        ->where('run_id', $runId)
                        ->where('node_id', $committedNodeId)
                        ->lockForUpdate()
                        ->get();

                    DB::table($table)
                        ->where('run_id', $runId)
                        ->where('node_id', $committedNodeId)
                        ->whereNull('sealed_at')
                        ->update(['sealed_at' => now('UTC')]);
                });
                $outcomes[$runId] = 'sealed';
            } catch (Throwable $e) {
                $outcomes[$runId] = 'aborted:'.$e::class;
            }
        }

        return $outcomes;
    };
}

beforeEach(function (): void {
    if (! durableBranchVoidSealDriverSupported()) {
        $this->markTestSkipped(
            'Durable branch void/seal concurrency test requires a shared database engine '
            .'reachable from child processes (mysql/pgsql). Current driver: '
            .DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');

    // Child-first delete (events cascade from histories anyway) for a clean slate.
    DB::table('swarm_stream_events')->delete();
    DB::table('swarm_run_histories')->delete();
});

test('a crashed branch void racing a sibling post-join seal voids only the crashed branch, never throws SealedCausalWindowException, and leaves the sibling un-voided', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    $now = now('UTC');
    $runCount = 24;
    $crashedNodeId = 'parallel:0';
    $crashedEpoch = 1;
    $committedNodeId = 'parallel:1';
    $runIds = [];

    $log = app(CausalLogStore::class);

    for ($i = 0; $i < $runCount; $i++) {
        $runId = 'branch-void-seal-'.$i;
        $runIds[] = $runId;

        // Parent history row the stream-event FK (run_id -> swarm_run_histories) needs.
        DB::table('swarm_run_histories')->insert([
            'run_id' => $runId,
            'swarm_class' => 'ExampleSwarm',
            'topology' => 'parallel',
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

        // Seed the crashed branch's prior attempt (parallel:0, epoch 1) — the void
        // target — and the committed sibling (parallel:1, epoch 1) — the seal target.
        // Recorded through the real store so node_id + attempt_epoch are stamped, the
        // same shape the durable branch sink writes.
        $log->record($runId, (new SwarmTextDelta(
            id: $runId.'-crashed-0',
            runId: $runId,
            stepIndex: 0,
            agentClass: 'FlakyAgent',
            delta: 'partial',
            timestamp: SwarmStreamEvent::timestamp(),
        ))->withNodeId($crashedNodeId)->withAttemptEpoch($crashedEpoch), 0);

        $log->record($runId, (new SwarmTextDelta(
            id: $runId.'-sibling-0',
            runId: $runId,
            stepIndex: 0,
            agentClass: 'SiblingAgent',
            delta: 'sibling-out',
            timestamp: SwarmStreamEvent::timestamp(),
        ))->withNodeId($committedNodeId)->withAttemptEpoch(1), 0);
    }

    // Both workers walk the SAME ordered run list, started concurrently, so for each
    // run a voidNodeAttempt(parallel:0) and a seal-of(parallel:1) race head-on.
    $results = $concurrency->driver('process')->run([
        durableBranchVoidWorker($runIds, $crashedNodeId, $crashedEpoch),
        durableBranchSealWorker($runIds, $committedNodeId),
    ]);

    expect($results)->toHaveCount(2);

    [$voidOutcomes] = $results;

    $inconsistent = [];

    foreach ($runIds as $runId) {
        $outcome = $voidOutcomes[$runId] ?? 'missing';

        // The crashed branch's first event and its void-edge (if any).
        $crashedFirstUuid = DB::table('swarm_stream_events')->where('run_id', $runId)
            ->where('node_id', $crashedNodeId)->where('attempt_epoch', $crashedEpoch)
            ->orderBy('id')->value('event_uuid');

        $crashedVoidCount = DB::table('swarm_stream_events')->where('run_id', $runId)
            ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)
            ->where('void_target_event_uuid', $crashedFirstUuid)->count();

        // The sibling must NEVER carry a void-edge against any of its events.
        $siblingUuids = DB::table('swarm_stream_events')->where('run_id', $runId)
            ->where('node_id', $committedNodeId)->pluck('event_uuid')->all();
        $siblingVoidCount = $siblingUuids === [] ? 0 : DB::table('swarm_stream_events')->where('run_id', $runId)
            ->whereNotNull('void_target_event_uuid')
            ->whereIn('void_target_event_uuid', $siblingUuids)->count();

        // (b) The committed sibling is present and un-voided in the clean fold.
        $cleanSibling = array_values(array_filter(
            CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Clean),
            fn ($event) => ! ($event instanceof VoidedEvent)
                && is_string(($event->toArray()['node_id'] ?? null))
                && $event->toArray()['node_id'] === $committedNodeId,
        ));

        $legal = match (true) {
            // (a)+(c): the void landed — exactly one edge against the crashed branch,
            // the sibling carries none, and the sibling survives the clean fold.
            $outcome === 'voided' => $crashedVoidCount === 1
                && $siblingVoidCount === 0
                && $cleanSibling !== [],
            // 'rejected-sealed' is the breach this test guards against — never legal.
            $outcome === 'rejected-sealed' => false,
            // The void lost the row contention as deadlock victim: no edge written,
            // sibling still un-voided and present. A retry would void on the next resume.
            str_starts_with($outcome, 'aborted:') => $crashedVoidCount === 0
                && $siblingVoidCount === 0
                && $cleanSibling !== [],
            default => false,
        };

        if (! $legal) {
            $inconsistent[$runId] = [
                'outcome' => $outcome,
                'crashed_void_edges' => $crashedVoidCount,
                'sibling_void_edges' => $siblingVoidCount,
                'clean_sibling_present' => $cleanSibling !== [],
            ];
        }
    }

    expect($inconsistent)->toBe([]);
});
