<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\CacheArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\CacheContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheStreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseStreamEventStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SwarmHealthRecordingCacheStore extends ArrayStore
{
    /** @var array<string, bool> */
    public static array $keys = [];

    public function put($key, $value, $seconds): bool
    {
        self::$keys[(string) $key] = true;

        return parent::put($key, $value, $seconds);
    }

    public function forget($key): bool
    {
        unset(self::$keys[(string) $key]);

        return parent::forget($key);
    }
}

class SwarmHealthFailingCacheStore extends ArrayStore
{
    public function put($key, $value, $seconds): bool
    {
        return false;
    }
}

beforeEach(function (): void {
    Cache::extend('swarm-health-recording', fn (): Repository => new Repository(new SwarmHealthRecordingCacheStore));
    Cache::extend('swarm-health-failing', fn (): Repository => new Repository(new SwarmHealthFailingCacheStore));

    config()->set('cache.stores.swarm-health-recording', ['driver' => 'swarm-health-recording']);
    config()->set('cache.stores.swarm-health-failing', ['driver' => 'swarm-health-failing']);

    SwarmHealthRecordingCacheStore::$keys = [];
});

test('cache backed persistence stores assert readiness and remove probe keys', function (): void {
    config()->set('swarm.context.store', 'swarm-health-recording');
    config()->set('swarm.artifacts.store', 'swarm-health-recording');
    config()->set('swarm.history.store', 'swarm-health-recording');
    config()->set('swarm.streaming.replay.store', 'swarm-health-recording');

    app(CacheContextStore::class)->assertReady();
    app(CacheArtifactRepository::class)->assertReady();
    app(CacheRunHistoryStore::class)->assertReady();
    app(CacheStreamEventStore::class)->assertReady();

    expect(SwarmHealthRecordingCacheStore::$keys)->toBe([]);
});

test('cache backed persistence readiness fails clearly when a cache write fails', function (): void {
    config()->set('swarm.context.store', 'swarm-health-failing');

    expect(fn () => app(CacheContextStore::class)->assertReady())
        ->toThrow(SwarmException::class, 'Laravel Swarm [context] cache store [swarm-health-failing] failed to write readiness probe.');
});

test('database stream event store asserts readiness', function (): void {
    app(DatabaseStreamEventStore::class)->assertReady();

    config()->set('swarm.tables.stream_events', 'missing_swarm_stream_events');

    expect(fn () => app(DatabaseStreamEventStore::class)->assertReady())
        ->toThrow(SwarmException::class, 'Database-backed stream replay requires the [missing_swarm_stream_events] table.');
});

test('swarm health passes for cache stores without durable readiness', function (): void {
    config()->set('swarm.tables.durable', 'missing_swarm_durable_runs');

    expect(Artisan::call('swarm:health'))->toBe(0);
    expect(Artisan::output())->toContain('Context');
    expect(Artisan::output())->not->toContain('Durable runtime');
});

test('swarm health durable option verifies durable database readiness', function (): void {
    expect(Artisan::call('swarm:health', ['--durable' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Durable runtime');

    config()->set('swarm.tables.durable', 'missing_swarm_durable_runs');

    expect(Artisan::call('swarm:health', ['--durable' => true]))->toBe(1);
    expect(Artisan::output())->toContain('missing_swarm_durable_runs');
});

test('swarm health json output is structured', function (): void {
    expect(Artisan::call('swarm:health', ['--json' => true]))->toBe(0);

    $payload = json_decode(Artisan::output(), true);

    expect($payload)
        ->toBeArray()
        ->and($payload['ok'])->toBeTrue()
        ->and($payload['checks'])->toHaveCount(4)
        ->and($payload['checks'][0])->toHaveKeys(['component', 'driver', 'store', 'status', 'details']);
});

test('swarm health identifies failing cache component', function (): void {
    config()->set('swarm.context.store', 'swarm-health-failing');
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(StreamEventStore::class);

    expect(Artisan::call('swarm:health'))->toBe(1);
    expect(Artisan::output())
        ->toContain('Context')
        ->toContain('swarm-health-failing')
        ->toContain('failed to write readiness probe');
});

// ---------------------------------------------------------------------------
// swarm:health --durable active context capture check (issue #11)
// ---------------------------------------------------------------------------

describe('active context capture check', function (): void {
    test('reports ok when swarm.capture.active_context is enabled', function (): void {
        config()->set('swarm.capture.active_context', true);

        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())
            ->toContain('Active context capture')
            ->toContain('swarm.capture.active_context is enabled');
    });

    test('reports failed when swarm.capture.active_context is disabled under --durable', function (): void {
        config()->set('swarm.capture.active_context', false);

        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(1);
        expect(Artisan::output())
            ->toContain('Active context capture')
            ->toContain('SWARM_CAPTURE_ACTIVE_CONTEXT=true');
    });

    test('does not run without --durable', function (): void {
        config()->set('swarm.capture.active_context', false);

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->not->toContain('Active context capture');
    });
});

// ---------------------------------------------------------------------------
// swarm:health --durable outbox staleness checks (Plan 1 — graduated states)
// ---------------------------------------------------------------------------

describe('outbox staleness check', function (): void {
    beforeEach(function (): void {
        config()->set('swarm.persistence.driver', 'database');
        // Disable FK checks for the duration of these tests so we can insert
        // outbox rows with arbitrary run_ids without needing a parent run row.
        DB::statement('PRAGMA foreign_keys = OFF');
    });

    afterEach(function (): void {
        DB::statement('PRAGMA foreign_keys = ON');
    });

    test('missing outbox table returns failed status and exits 1', function (): void {
        config()->set('swarm.tables.durable_outbox', 'nonexistent_outbox_xyz');

        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(1);
        expect(Artisan::output())->toContain('does not exist');
    });

    test('empty outbox returns ok with "no pending rows"', function (): void {
        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain('no pending rows');
    });

    test('young unclaimed rows return ok with relay-active message', function (): void {
        // Row created just 5 seconds ago — well within the 120 s default threshold.
        DB::table('swarm_durable_outbox')->insert([
            'run_id' => (string) Str::uuid(),
            'dispatch_type' => 'step',
            'payload' => '{}',
            'queue_connection' => null,
            'queue_name' => null,
            'available_at' => now()->subSeconds(5),
            'reserved_at' => null,
            'created_at' => now()->subSeconds(5),
        ]);

        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())
            ->toContain('pending row(s), relay appears active')
            ->not->toContain('warning');
    });

    test('stale unclaimed rows produce a warning status', function (): void {
        // Insert a row with reserved_at = null and created_at well past the
        // default 2 × 60 s = 120 s staleness threshold.
        DB::table('swarm_durable_outbox')->insert([
            'run_id' => (string) Str::uuid(),
            'dispatch_type' => 'step',
            'payload' => '{}',
            'queue_connection' => null,
            'queue_name' => null,
            'available_at' => now()->subMinutes(3),
            'reserved_at' => null,
            'created_at' => now()->subMinutes(3),
        ]);

        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain('warning');
    });

    test('stale reserved rows produce a warning status (F2 regression guard)', function (): void {
        // Insert a row whose reserved_at is itself stale (relay worker died
        // between claim and dispatch). This branch is covered by the
        // orWhere('reserved_at', '<', $staleThreshold) condition introduced in
        // the F2 fix; the test would fail if that clause were reverted.
        DB::table('swarm_durable_outbox')->insert([
            'run_id' => (string) Str::uuid(),
            'dispatch_type' => 'step',
            'payload' => '{}',
            'queue_connection' => null,
            'queue_name' => null,
            'available_at' => now()->subMinutes(3),
            'reserved_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
        ]);

        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain('warning');
    });

    test('custom stale_warning_threshold_seconds overrides the default 2x formula', function (): void {
        // Set a very short threshold (10 s) so a 30 s old row triggers a warning.
        config()->set('swarm.durable.relay.stale_warning_threshold_seconds', 10);

        DB::table('swarm_durable_outbox')->insert([
            'run_id' => (string) Str::uuid(),
            'dispatch_type' => 'step',
            'payload' => '{}',
            'queue_connection' => null,
            'queue_name' => null,
            'available_at' => now()->subSeconds(30),
            'reserved_at' => null,
            'created_at' => now()->subSeconds(30),
        ]);

        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain('warning')->toContain('10s');
    });
});

// ---------------------------------------------------------------------------
// Plan 2 — Relay scheduling note
// ---------------------------------------------------------------------------

describe('relay scheduling note', function (): void {
    test('--durable output includes a Relay scheduling row with status note', function (): void {
        Artisan::call('swarm:health', ['--durable' => true]);

        expect(Artisan::output())
            ->toContain('Relay scheduling')
            ->toContain('note');
    });

    test('exit code is 0 when only the note row is present alongside clean checks', function (): void {
        // Outbox is empty; all stores are healthy — only note rows, no failures.
        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Plan 4 — Queue-routing mismatch detection
// ---------------------------------------------------------------------------

describe('queue routing check', function (): void {
    beforeEach(function (): void {
        config()->set('swarm.persistence.driver', 'database');
        DB::statement('PRAGMA foreign_keys = OFF');
    });

    afterEach(function (): void {
        DB::statement('PRAGMA foreign_keys = ON');
    });

    test('missing outbox table returns failed status for queue routing', function (): void {
        config()->set('swarm.tables.durable_outbox', 'nonexistent_outbox_xyz');

        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(1);
        // Both the staleness check and routing check report failed for a missing table.
        expect(Artisan::output())->toContain('does not exist');
    });

    test('outbox rows with known queue_connection return ok', function (): void {
        // Use the sync connection which is always present in Laravel's default config.
        DB::table('swarm_durable_outbox')->insert([
            'run_id' => (string) Str::uuid(),
            'dispatch_type' => 'step',
            'payload' => '{}',
            'queue_connection' => 'sync',
            'queue_name' => null,
            'available_at' => now()->subSeconds(5),
            'reserved_at' => null,
            'created_at' => now()->subSeconds(5),
        ]);

        Artisan::call('swarm:health', ['--durable' => true]);

        expect(Artisan::output())->toContain('all connections known');
    });

    test('outbox rows with unknown queue_connection return warning', function (): void {
        DB::table('swarm_durable_outbox')->insert([
            'run_id' => (string) Str::uuid(),
            'dispatch_type' => 'step',
            'payload' => '{}',
            'queue_connection' => 'nonexistent_queue_driver_xyz',
            'queue_name' => null,
            'available_at' => now()->subSeconds(5),
            'reserved_at' => null,
            'created_at' => now()->subSeconds(5),
        ]);

        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())
            ->toContain('Outbox queue routing')
            ->toContain('warning')
            ->toContain('unknown queue_connection');
    });
});
