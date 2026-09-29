<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Process-concurrency coverage for DatabaseCallbackDeliveryOutbox::drain() under
 * real parallel relay workers.
 *
 * The feature tests exercise the observable contracts, but only a real shared DB
 * that honors FOR UPDATE SKIP LOCKED can prove two relay workers draining the
 * callback lane simultaneously never hand the same row to two delivery jobs.
 *
 * Skips cleanly on connections without SKIP LOCKED (e.g. SQLite), mirroring the
 * drain's own guard and the audit-outbox concurrency test.
 */
pest()->group('process-concurrency', 'skip-locked-real-db');

function callbackConcurrencyDriverSupported(): bool
{
    return ! in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true);
}

/**
 * Worker closure run in child PHP processes. Built by a free function so its scope
 * class is null and it serializes cleanly to the child (see the audit-outbox
 * concurrency test for the full rationale). Uses a null queue connection so the
 * drain's job dispatch is inert — this test proves claim disjointness, not delivery.
 */
function callbackConcurrencyWorker(?int $perWorker = null): Closure
{
    return static function () use ($perWorker): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        config()->set('swarm.callbacks.enabled', true);
        config()->set('queue.connections.cbnull', ['driver' => 'null']);
        config()->set('queue.default', 'cbnull');

        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        app()->forgetInstance(CallbackDeliveryOutbox::class);

        $result = $perWorker === null
            ? app(CallbackDeliveryOutbox::class)->drain()
            : app(CallbackDeliveryOutbox::class)->drain($perWorker);

        return [
            'claimed' => $result->claimed,
            'dispatched' => $result->dispatched,
            'reclaimed' => $result->reclaimed,
        ];
    };
}

function seedPendingCallback(string $runId, ?Carbon $reservedAt = null): void
{
    DB::table('swarm_callback_deliveries')->insert([
        'run_id' => $runId,
        'slot' => 'then',
        'callback' => 'x',
        'context' => null,
        'attempts' => 0,
        'status' => 'pending',
        'last_error' => null,
        'last_attempted_at' => null,
        'reserved_at' => $reservedAt,
        'created_at' => Carbon::now('UTC'),
        'updated_at' => Carbon::now('UTC'),
    ]);
}

beforeEach(function (): void {
    if (! callbackConcurrencyDriverSupported()) {
        $this->markTestSkipped(
            'Callback outbox SKIP LOCKED concurrency test requires a shared database engine that '
            .'honors FOR UPDATE SKIP LOCKED (mysql/pgsql). Current driver: '.DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.callbacks.enabled', true);
    app()->forgetInstance(CallbackDeliveryOutbox::class);

    DB::table('swarm_callback_deliveries')->truncate();
});

test('two parallel drains claim disjoint subsets of pending callbacks', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    $totalRows = 8;
    for ($i = 0; $i < $totalRows; $i++) {
        seedPendingCallback('r-cb-'.$i);
    }

    $worker = callbackConcurrencyWorker(4);
    $results = $concurrency->driver('process')->run([$worker, $worker]);
    [$a, $b] = [$results[0], $results[1]];

    // Sum === total proves SKIP LOCKED partitioned the rows: no row was claimed
    // (and thus handed to a delivery job) by both workers.
    expect($a['claimed'] + $b['claimed'])->toBe($totalRows);
    expect($a['dispatched'] + $b['dispatched'])->toBe($totalRows);
});

test('two parallel drains reclaim a single stale reservation exactly once', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    seedPendingCallback('r-cb-stale', Carbon::now('UTC')->subMinutes(5));

    $worker = callbackConcurrencyWorker();
    $results = $concurrency->driver('process')->run([$worker, $worker]);
    [$a, $b] = [$results[0], $results[1]];

    expect($a['claimed'] + $b['claimed'])->toBe(1);
    expect($a['reclaimed'] + $b['reclaimed'])->toBe(1);
    expect($a['dispatched'] + $b['dispatched'])->toBe(1);
});
