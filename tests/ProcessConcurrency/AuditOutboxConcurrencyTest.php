<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\Facades\DB;

/**
 * Process-concurrency coverage for DatabaseAuditOutbox::drain() under real
 * parallel relay workers.
 *
 * The unit tests in tests/Unit/Audit/AuditOutboxTest.php exercise the
 * observable contracts (bounded limit, stale-reclaim threshold) but cannot
 * prove that FOR UPDATE SKIP LOCKED prevents double-claim when two workers
 * call drain() simultaneously, because the testbench in-memory SQLite does
 * not honor the lock clause.
 *
 * This test spawns two parallel drain() calls through the Laravel process
 * concurrency driver against a real shared database and asserts:
 *
 *   - Scenario 1: each worker claims a disjoint subset of pending row ids.
 *   - Scenario 2: a single stale reservation is reclaimed by exactly one
 *                 worker — never both.
 *
 * The test must skip cleanly on connections that do not support
 * SKIP LOCKED (e.g., SQLite) rather than fail, mirroring the existing
 * skip pattern in DatabaseAuditOutbox::drain itself.
 */
// Tagged `skip-locked-real-db` so the CI lane (`test:process-concurrency:ci`)
// can exclude it under `--fail-on-skipped`: this suite requires a shared
// MySQL/Postgres connection to actually run and skips on the default
// in-memory SQLite. The non-CI lane (`test:process-concurrency`) still
// discovers and exercises it whenever a real DB is configured.
pest()->group('process-concurrency', 'skip-locked-real-db');

/**
 * Returns true if the configured testing connection is a real, shared DB
 * engine that honors FOR UPDATE SKIP LOCKED. SQLite — the testbench default —
 * is excluded because:
 *
 *   1. :memory: SQLite is per-process and cannot be shared with the child
 *      processes spawned by the process concurrency driver.
 *   2. SQLite has no SKIP LOCKED clause to honor.
 *
 * Operators running this lane against a real MySQL/Postgres instance must
 * configure the testing connection via env (DB_CONNECTION/DB_HOST/...) in
 * advance.
 */
function auditOutboxConcurrencyDriverSupported(): bool
{
    return ! in_array(
        DB::connection()->getDriverName(),
        ['sqlite', 'sqlsrv'],
        true,
    );
}

/**
 * Build the worker closure that two parallel drain() calls execute in
 * child PHP processes.
 *
 * Two things must be true for the closure to survive the trip:
 *
 *   1. Closure scope class must be resolvable in the child. The child
 *      runs `php artisan invoke-serialized-closure` against testbench's
 *      bare Laravel, which does NOT boot Pest. A closure defined inside
 *      a Pest `test(...)` body would have scope class
 *      `P\Tests\ProcessConcurrency\AuditOutboxConcurrencyTest` — a Pest
 *      runtime class that doesn't exist in the child. Defining the
 *      closure inside this free function gives it a null scope class,
 *      which serializes cleanly. `static` alone does NOT fix this — it
 *      strips $this but keeps the scope class (verified locally).
 *
 *   2. The swarm package must be bootstrapped in the child container.
 *      testbench discovers packages only from
 *      vendor/orchestra/testbench-core/laravel/bootstrap/cache/packages.php,
 *      which does not include the package currently being tested. So
 *      the child knows nothing about BuiltByBerry\LaravelSwarm. We
 *      register the provider explicitly and set the minimum config the
 *      AuditOutbox binding needs to resolve to DatabaseAuditOutbox.
 *      The DB connection itself flows via inherited env vars (testbench
 *      reads DB_* env at boot).
 *
 * Each worker swaps in its own in-process RecordingSwarmAuditSink so
 * the parent can compare which run_ids each child observed, then calls
 * drain() and returns a uniform result shape covering both scenarios.
 */
function auditOutboxConcurrencyWorker(?int $perWorker = null): Closure
{
    return static function () use ($perWorker): array {
        // Bootstrap the swarm package in the child container. Mirror the
        // minimum subset of TestCase::defineEnvironment() needed for the
        // AuditOutbox binding to resolve and for sealed-payload writes to
        // round-trip cleanly under the test fixture.
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $sink = new RecordingSwarmAuditSink;
        app()->instance(SwarmAuditSink::class, $sink);
        app()->forgetInstance(AuditOutbox::class);

        $result = $perWorker === null
            ? app(AuditOutbox::class)->drain()
            : app(AuditOutbox::class)->drain($perWorker);

        return [
            'claimed' => $result->claimed,
            'replayed' => $result->replayed,
            'reclaimed' => $result->reclaimed,
            'run_ids' => array_values(array_map(
                static fn (array $r): ?string => isset($r['run_id']) && is_string($r['run_id']) ? $r['run_id'] : null,
                $sink->allRecords(),
            )),
        ];
    };
}

beforeEach(function (): void {
    if (! auditOutboxConcurrencyDriverSupported()) {
        $this->markTestSkipped(
            'Audit outbox SKIP LOCKED concurrency test requires a shared database '
            .'engine that honors FOR UPDATE SKIP LOCKED (mysql/pgsql). Current '
            .'driver: '.DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');
    app()->instance(SwarmAuditSink::class, new RecordingSwarmAuditSink);
    app()->forgetInstance(AuditOutbox::class);

    DB::table('swarm_audit_outbox')->truncate();
});

test('two parallel drain() calls claim disjoint subsets of pending rows', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    $outbox = app(AuditOutbox::class);
    $totalRows = 8;
    $perWorker = 4;

    for ($i = 0; $i < $totalRows; $i++) {
        $outbox->enqueue('run.failed', [
            'run_id' => 'r-concurrent-'.$i,
            'category' => 'run.failed',
        ]);
    }

    expect(DB::table('swarm_audit_outbox')->where('status', 'pending')->count())
        ->toBe($totalRows);

    // Worker closure is built by a free function (see auditOutboxConcurrencyWorker)
    // so its scope class is null. A closure defined inline here would be scoped to
    // the Pest auto-generated `P\Tests\...AuditOutboxConcurrencyTest` class, which
    // the child PHP process can't resolve — see the helper's docblock.
    $workerClosure = auditOutboxConcurrencyWorker($perWorker);

    $results = $concurrency->driver('process')->run([
        $workerClosure,
        $workerClosure,
    ]);

    [$a, $b] = [$results[0], $results[1]];

    $idsA = array_values(array_filter($a['run_ids'], fn ($v): bool => is_string($v)));
    $idsB = array_values(array_filter($b['run_ids'], fn ($v): bool => is_string($v)));

    // Disjoint: no row id appears in both workers' replay logs.
    expect(array_intersect($idsA, $idsB))->toBe([]);

    // Together: both workers cover every pending row exactly once.
    $combined = array_merge($idsA, $idsB);
    sort($combined);
    expect($combined)->toHaveCount($totalRows);
    expect(array_unique($combined))->toHaveCount($totalRows);

    // The drain results should reflect the same partition.
    expect($a['claimed'] + $b['claimed'])->toBe($totalRows);
    expect($a['replayed'] + $b['replayed'])->toBe($totalRows);

    // All rows were replayed and removed.
    expect(DB::table('swarm_audit_outbox')->count())->toBe(0);
});

test('two parallel drain() calls reclaim a single stale reservation exactly once', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    $outbox = app(AuditOutbox::class);
    $outbox->enqueue('run.failed', [
        'run_id' => 'r-stale-shared',
        'category' => 'run.failed',
    ]);

    // Simulate a relay worker that claimed the row then crashed before completing
    // the drain — reserved_at is older than the reservation timeout (default 60s).
    DB::table('swarm_audit_outbox')
        ->where('run_id', 'r-stale-shared')
        ->update(['reserved_at' => now()->subMinutes(5)]);

    expect(DB::table('swarm_audit_outbox')->where('status', 'pending')->count())->toBe(1);

    // Same free-function pattern as scenario 1; see auditOutboxConcurrencyWorker.
    $workerClosure = auditOutboxConcurrencyWorker();

    $results = $concurrency->driver('process')->run([
        $workerClosure,
        $workerClosure,
    ]);

    [$a, $b] = [$results[0], $results[1]];

    // Exactly one worker reclaimed and replayed the stale row; the other saw
    // nothing. Both workers must NOT race past SKIP LOCKED and double-claim.
    expect($a['claimed'] + $b['claimed'])->toBe(1);
    expect($a['replayed'] + $b['replayed'])->toBe(1);
    expect($a['reclaimed'] + $b['reclaimed'])->toBe(1);

    // The row was deleted by the worker that successfully replayed it.
    expect(DB::table('swarm_audit_outbox')->where('run_id', 'r-stale-shared')->count())
        ->toBe(0);
});
