<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Process-concurrency coverage for the child-swarm dispatch claim in
 * DurableChildSwarmCoordinator::dispatchChildIntent() (issue #431).
 *
 * The race needs no infrastructure fault. `swarm:recover` has no overlap lock
 * and undispatchedChildRuns() has no grace floor, so a recovery sweep can select
 * a child the parent is inline-dispatching right now — or two sweeps can select
 * the same child. Before the fix both workers saw `find() === null`, both called
 * dispatchDurable(), and the loser caught the unique-key QueryException and wrote
 * `failed` over the WINNER's live child, releasing the parent's wait with
 * `child_failed` while the child executed normally.
 *
 * The fix makes the CLAIM authoritative rather than the error classifier: only
 * the worker that wins the conditional `dispatched_at IS NULL` update may
 * dispatch. That is what this lane proves, at the only layer where it is
 * provable — two real OS processes contending for one row.
 *
 * Scenario: N workers race markChildRunDispatched() against the same child.
 * The invariants:
 *   - exactly ONE worker wins the claim, so a duplicate start() is unreachable;
 *   - the losers' stamps never overwrite the winner's `dispatched_at`;
 *   - a loser cannot release the winner's claim, because releaseChildRunDispatch()
 *     matches the exact stamped timestamp rather than merely "not null".
 *
 * The last invariant is the one that matters most: without exact-value matching a
 * losing worker's release would free a child that is genuinely executing, and the
 * next sweep would dispatch it a second time.
 */
// Tagged `skip-locked-real-db` so the CI lane (`test:process-concurrency:ci`)
// excludes it under `--fail-on-skipped`: it requires a shared MySQL/Postgres
// connection and skips on the default in-memory SQLite, which is per-process and
// cannot be reached by the child processes the concurrency driver spawns.
pest()->group('process-concurrency', 'skip-locked-real-db');

/**
 * True if the configured testing connection is a real, shared DB engine the
 * process concurrency driver can reach from child processes. Mirrors the
 * exclusions in DurableRunStateConcurrencyTest.
 */
function durableChildDispatchConcurrencyDriverSupported(): bool
{
    return ! in_array(
        DB::connection()->getDriverName(),
        ['sqlite', 'sqlsrv'],
        true,
    );
}

/**
 * Worker closure racing for one child's dispatch claim, then attempting to
 * release it with its OWN stamped timestamp.
 *
 * Built by a free function so its scope class is null and it serializes cleanly
 * to the child PHP process, which runs `php artisan invoke-serialized-closure`
 * against bare testbench (no Pest runtime, no this test file). Everything the
 * child needs is bootstrapped inline; only FQCN class references (resolved via
 * the composer autoloader) and framework globals are used.
 *
 * @return Closure(): array{claimed: bool, released: bool, stamp: string}
 */
function durableChildDispatchClaimWorker(string $childRunId, string $stamp, bool $release): Closure
{
    return static function () use ($childRunId, $stamp, $release): array {
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);

        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $store = app(DurableRunStore::class);
        $at = Carbon::parse($stamp)->utc();

        $claimed = $store->markChildRunDispatched($childRunId, $at);

        // Every worker attempts a release with its own stamp — the losers included.
        // A loser's release MUST no-op: it never held the claim.
        $released = $release ? $store->releaseChildRunDispatch($childRunId, $at) : false;

        return [
            'claimed' => $claimed,
            'released' => $released,
            'stamp' => $at->toDateTimeString('microsecond'),
        ];
    };
}

/**
 * Seed a parent durable run and one pending, unclaimed child intent.
 */
function durableChildDispatchRaceSeed(string $parentRunId, string $childRunId): void
{
    $now = Carbon::now('UTC');

    DB::table('swarm_durable_runs')->insert([
        'run_id' => $parentRunId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'execution_mode' => 'durable',
        'coordination_profile' => 'step_durable',
        'status' => 'waiting',
        'next_step_index' => 0,
        'total_steps' => 1,
        'completed_node_ids' => json_encode([]),
        'timeout_at' => $now->copy()->addHour(),
        'step_timeout_seconds' => 300,
        'attempts' => 0,
        'recovery_count' => 0,
        'retry_attempt' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('swarm_durable_child_runs')->insert([
        'parent_run_id' => $parentRunId,
        'child_run_id' => $childRunId,
        'child_swarm_class' => 'ExampleChildSwarm',
        'wait_name' => 'child:'.$childRunId,
        'context_payload' => json_encode(['run_id' => $childRunId]),
        'status' => 'pending',
        'output' => null,
        'failure' => null,
        'dispatched_at' => null,
        'terminal_event_dispatched_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

test('exactly one worker wins a child dispatch claim under real process concurrency', function () {
    if (! durableChildDispatchConcurrencyDriverSupported()) {
        $this->markTestSkipped('Requires a shared MySQL/Postgres connection; SQLite is per-process.');
    }

    $parentRunId = 'parent-'.bin2hex(random_bytes(8));
    $childRunId = 'child-'.bin2hex(random_bytes(8));
    durableChildDispatchRaceSeed($parentRunId, $childRunId);

    $base = Carbon::now('UTC')->startOfSecond();

    // Distinct stamps per worker, so the winner is identifiable from the row and a
    // loser's release provably targets a timestamp that was never persisted.
    $workers = [];
    for ($i = 0; $i < 4; $i++) {
        $workers[] = durableChildDispatchClaimWorker(
            $childRunId,
            $base->copy()->addSeconds($i)->toDateTimeString('microsecond'),
            release: false,
        );
    }

    /** @var array<int, array{claimed: bool, released: bool, stamp: string}> $results */
    $results = app(ConcurrencyManager::class)->driver('process')->run($workers);

    $winners = array_values(array_filter($results, fn (array $r): bool => $r['claimed']));

    expect($winners)->toHaveCount(1);

    $row = DB::table('swarm_durable_child_runs')->where('child_run_id', $childRunId)->first();

    // The winner's stamp is the one on the row: no loser overwrote it.
    expect(Carbon::parse($row->dispatched_at)->utc()->toDateTimeString('microsecond'))
        ->toBe($winners[0]['stamp'])
        ->and($row->status)->toBe('pending');
});

test('a losing worker cannot release the winning claim', function () {
    if (! durableChildDispatchConcurrencyDriverSupported()) {
        $this->markTestSkipped('Requires a shared MySQL/Postgres connection; SQLite is per-process.');
    }

    $parentRunId = 'parent-'.bin2hex(random_bytes(8));
    $childRunId = 'child-'.bin2hex(random_bytes(8));
    durableChildDispatchRaceSeed($parentRunId, $childRunId);

    $base = Carbon::now('UTC')->startOfSecond();

    $workers = [];
    for ($i = 0; $i < 4; $i++) {
        $workers[] = durableChildDispatchClaimWorker(
            $childRunId,
            $base->copy()->addSeconds($i)->toDateTimeString('microsecond'),
            release: true,
        );
    }

    /** @var array<int, array{claimed: bool, released: bool, stamp: string}> $results */
    $results = app(ConcurrencyManager::class)->driver('process')->run($workers);

    // The invariant is released === claimed, per worker. Were the release written as
    // `whereNotNull('dispatched_at')`, a worker that LOST the claim would still free
    // it — freeing a child another worker is genuinely dispatching, so the next sweep
    // would run it a second time.
    //
    // Note this scenario deliberately does NOT assert a single winner: each worker
    // hands its claim straight back, so a later worker can legitimately take the
    // now-free claim. Single-winner exclusivity is the previous test's job, where no
    // worker releases.
    foreach ($results as $result) {
        expect($result['released'])->toBe($result['claimed']);
    }

    expect(array_filter($results, fn (array $r): bool => $r['claimed']))->not->toBeEmpty();

    // Every claimer released, so the child ends selectable again — the claim is a
    // lease, not a brand.
    $row = DB::table('swarm_durable_child_runs')->where('child_run_id', $childRunId)->first();

    expect($row->dispatched_at)->toBeNull()
        ->and($row->status)->toBe('pending');
});
