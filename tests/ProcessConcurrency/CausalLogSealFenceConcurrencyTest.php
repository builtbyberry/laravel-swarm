<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SealedCausalWindowException;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\Facades\DB;

/**
 * Process-concurrency coverage for the seal-fence inside
 * DatabaseCausalLogStore::appendVoidEdge() (issue #282).
 *
 * appendVoidEdge() reads the target row with lockForUpdate(), checks sealed_at,
 * then inserts the void-edge — all in one transaction. The invariant: a void-edge
 * against a SEALED target never lands. The danger without the lock is a TOCTOU
 * window: the append reads sealed_at = null, a concurrent seal sets sealed_at, and
 * the append inserts anyway against now-sealed history.
 *
 * Per the project's concurrency-test philosophy (mirrors
 * DurableRunStateConcurrencyTest): the multi-process driver gives no interleaving
 * guarantee, so the deterministic proof that the guard closes the window lives in
 * the unit test (a pre-sealed target makes appendVoidEdge throw, timing-free, in
 * tests/Unit/Persistence/CausalLogTest.php). This test's complementary job is to
 * run an append worker and a seal worker head-on against the SAME targets on a
 * real engine and assert that every (append-outcome, final-row-state) pair is one
 * of the legal serial outcomes — never an inconsistent one.
 */
// Tagged `skip-locked-real-db` so the CI lane excludes it under
// `--fail-on-skipped`: it needs a shared MySQL/Postgres connection and skips on
// in-memory SQLite, which is per-process (and has no row-lock semantics worth
// racing) and unreachable by the child processes the concurrency driver spawns.
pest()->group('process-concurrency', 'skip-locked-real-db');

/**
 * True if the configured testing connection is a real, shared DB engine the
 * process concurrency driver can reach from child processes. SQLite (the
 * testbench default) is excluded: :memory: is per-process and a file SQLite has
 * no row-lock semantics worth racing. sqlsrv is excluded to mirror the sibling
 * concurrency lanes.
 */
function causalLogSealFenceDriverSupported(): bool
{
    return ! in_array(
        DB::connection()->getDriverName(),
        ['sqlite', 'sqlsrv'],
        true,
    );
}

/**
 * Append worker: for each target id in order, try to append a void-edge against
 * it. Built by a free function so its scope class is null and it serializes
 * cleanly to the child PHP process (no Pest runtime, no $this). See
 * DurableRunStateConcurrencyTest for the full serialization rationale.
 *
 * @param  array<int, string>  $targetIds
 */
function causalLogSealFenceAppendWorker(string $runId, array $targetIds): Closure
{
    return static function () use ($runId, $targetIds): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $store = app(CausalLogStore::class);
        $outcomes = [];

        foreach ($targetIds as $targetId) {
            try {
                $store->appendVoidEdge($runId, CausalVoidEdgeType::Supersedes, $targetId, 'race:'.$targetId);
                $outcomes[$targetId] = 'appended';
            } catch (SealedCausalWindowException) {
                $outcomes[$targetId] = 'rejected-sealed';
            } catch (Throwable $e) {
                // Lost the row contention with the seal: the engine aborted this
                // transaction as the deadlock victim. It rolled back without
                // inserting a void-edge — a legal "append lost" outcome.
                $outcomes[$targetId] = 'aborted:'.$e::class;
            }
        }

        return $outcomes;
    };
}

/**
 * Seal worker: for each target id in order, take the same row lock the append
 * worker takes and set sealed_at — standing in for the #287 compactor. Both
 * workers contend on the same target row, so they serialize.
 *
 * @param  array<int, string>  $targetIds
 */
function causalLogSealFenceSealWorker(string $runId, array $targetIds): Closure
{
    return static function () use ($runId, $targetIds): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $table = (string) config('swarm.tables.stream_events', 'swarm_stream_events');
        $outcomes = [];

        foreach ($targetIds as $targetId) {
            try {
                DB::transaction(function () use ($table, $runId, $targetId): void {
                    DB::table($table)
                        ->where('run_id', $runId)
                        ->where('event_uuid', $targetId)
                        ->lockForUpdate()
                        ->first();

                    DB::table($table)
                        ->where('run_id', $runId)
                        ->where('event_uuid', $targetId)
                        ->update(['sealed_at' => now('UTC')]);
                });
                $outcomes[$targetId] = 'sealed';
            } catch (Throwable $e) {
                $outcomes[$targetId] = 'aborted:'.$e::class;
            }
        }

        return $outcomes;
    };
}

beforeEach(function (): void {
    if (! causalLogSealFenceDriverSupported()) {
        $this->markTestSkipped(
            'Causal-log seal-fence concurrency test requires a shared database engine '
            .'reachable from child processes (mysql/pgsql). Current driver: '
            .DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');

    // Child-first delete (events cascade from histories anyway) for a clean slate.
    DB::table('swarm_stream_events')->delete();
    DB::table('swarm_run_histories')->delete();
});

test('a void-edge append racing a seal of the same target never produces an inconsistent state', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    $now = now('UTC');
    $runId = 'seal-fence-race';
    $targetCount = 24;
    $targetIds = [];

    // Parent history row the stream-event FK (run_id -> swarm_run_histories) needs.
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

    for ($i = 0; $i < $targetCount; $i++) {
        $targetId = 'target-'.$i;
        $targetIds[] = $targetId;

        // Seed an unsealed base event the append worker can void and the seal
        // worker can seal.
        DB::table('swarm_stream_events')->insert([
            'run_id' => $runId,
            'event_uuid' => $targetId,
            'event_type' => 'swarm_stream_start',
            'payload' => json_encode(['type' => 'swarm_stream_start', 'id' => $targetId, 'run_id' => $runId]),
            'void_type' => null,
            'void_target_event_uuid' => null,
            'void_reason' => null,
            'sealed_at' => null,
            'expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // Both workers walk the SAME ordered target list, started concurrently, so for
    // each target an appendVoidEdge() and a seal race head-on on the same row.
    $results = $concurrency->driver('process')->run([
        causalLogSealFenceAppendWorker($runId, $targetIds),
        causalLogSealFenceSealWorker($runId, $targetIds),
    ]);

    expect($results)->toHaveCount(2);

    [$appendOutcomes] = $results;

    // For each target, the (append-outcome, final-row-state) pair must be one of
    // the legal serial outcomes that the lockForUpdate fence permits:
    //   - 'appended'        => a void-edge row exists (the append committed; the
    //                          target was unsealed under the lock at insert time).
    //   - 'rejected-sealed' => NO void-edge row, and the target is sealed (the
    //                          append observed the seal and threw).
    //   - 'aborted:*'       => NO void-edge row (deadlock victim rolled back).
    // Any other pair means the fence let an append land against a sealed target,
    // or lost/duplicated a row.
    $inconsistent = [];

    foreach ($targetIds as $targetId) {
        $outcome = $appendOutcomes[$targetId] ?? 'missing';

        $voidEdgeCount = DB::table('swarm_stream_events')
            ->where('run_id', $runId)
            ->where('event_type', 'swarm_causal_void_edge')
            ->where('void_target_event_uuid', $targetId)
            ->count();

        $targetSealed = DB::table('swarm_stream_events')
            ->where('run_id', $runId)
            ->where('event_uuid', $targetId)
            ->whereNotNull('sealed_at')
            ->exists();

        $legal = match (true) {
            $outcome === 'appended' => $voidEdgeCount === 1,
            $outcome === 'rejected-sealed' => $voidEdgeCount === 0 && $targetSealed,
            str_starts_with($outcome, 'aborted:') => $voidEdgeCount === 0,
            default => false,
        };

        if (! $legal) {
            $inconsistent[$targetId] = [
                'outcome' => $outcome,
                'void_edges' => $voidEdgeCount,
                'target_sealed' => $targetSealed,
            ];
        }
    }

    expect($inconsistent)->toBe([]);
});
