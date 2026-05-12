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
// swarm:health --durable outbox staleness checks
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

    test('clean outbox returns ok status', function (): void {
        // No rows — outbox is empty, health check should be clean.
        $exitCode = Artisan::call('swarm:health', ['--durable' => true]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->not->toContain('failed');
    });
});
