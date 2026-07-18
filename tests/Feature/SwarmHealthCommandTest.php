<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\CacheArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\CacheContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheStreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseStreamEventStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
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

class SwarmHealthRecordingAuditSink implements SwarmAuditSink
{
    public function emit(string $category, array $payload): void
    {
        // no-op: presence of the binding is what the health check verifies.
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

    // 4 persistence checks + 3 governed-by-default checks (Guardrails, Audit sink, Capture policy).
    expect($payload)
        ->toBeArray()
        ->and($payload['ok'])->toBeTrue()
        ->and($payload['checks'])->toHaveCount(7)
        ->and($payload['checks'][0])->toHaveKeys(['component', 'driver', 'store', 'status', 'details']);

    // Every check — including the new governance checks — carries the same shape.
    foreach ($payload['checks'] as $check) {
        expect($check)->toHaveKeys(['component', 'driver', 'store', 'status', 'details']);
    }
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

// ---------------------------------------------------------------------------
// swarm:health audit outbox checks (review F4)
// ---------------------------------------------------------------------------

describe('audit outbox checks', function (): void {
    beforeEach(function (): void {
        config()->set('swarm.persistence.driver', 'database');
    });

    test('audit outbox checks run on bare swarm:health (default-on)', function (): void {
        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain('Audit outbox');
    });

    test('--audit flag runs only audit checks, skips persistence and durable', function (): void {
        $exitCode = Artisan::call('swarm:health', ['--audit' => true]);

        expect($exitCode)->toBe(0);
        $output = Artisan::output();
        expect($output)->toContain('Audit outbox');
        expect($output)->not->toContain('Context');
        expect($output)->not->toContain('Stream replay');
    });

    test('audit checks skip silently on the cache driver', function (): void {
        config()->set('swarm.persistence.driver', 'cache');

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->not->toContain('Audit outbox');
    });

    test('missing audit outbox table returns failed status', function (): void {
        config()->set('swarm.tables.audit_outbox', 'nonexistent_audit_outbox_xyz');

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(1);
        expect(Artisan::output())->toContain('Audit outbox')->toContain('does not exist');
    });

    test('stale pending audit rows produce a warning status', function (): void {
        DB::table('swarm_audit_outbox')->insert([
            'category' => 'run.failed',
            'run_id' => 'r-stale',
            'payload' => '{}',
            'attempts' => 1,
            'status' => 'pending',
            'last_error' => null,
            'last_attempted_at' => now()->subMinutes(5),
            'reserved_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain('staleness')->toContain('warning');
    });

    test('aged unclaimed audit rows produce a warning status (F1 — relay down before claim)', function (): void {
        // Never reserved (relay never claimed it), but old: the relay is unscheduled,
        // misrouted, or starved. Must not report "relay appears active".
        DB::table('swarm_audit_outbox')->insert([
            'category' => 'run.failed',
            'run_id' => 'r-unclaimed',
            'payload' => '{}',
            'attempts' => 0,
            'status' => 'pending',
            'last_error' => null,
            'last_attempted_at' => null,
            'reserved_at' => null,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())
            ->toContain('Audit outbox staleness')
            ->toContain('warning')
            ->toContain('unclaimed pending row(s) aging')
            ->not->toContain('relay appears active');
    });

    test('aged unclaimed audit rows warn under a non-UTC app timezone (F1 — UTC-stored columns)', function (): void {
        // Production stores created_at via Carbon::now('UTC'); the health check must read it
        // in the same frame. Under a non-UTC default timezone a bare now() compares app-local
        // wall-clock against the UTC column and skews the boundary by the offset, hiding a
        // genuinely-aged row. This test fails if any health threshold reverts to bare now().
        $originalTz = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            DB::table('swarm_audit_outbox')->insert([
                'category' => 'run.failed',
                'run_id' => 'r-tz',
                'payload' => '{}',
                'attempts' => 0,
                'status' => 'pending',
                'last_error' => null,
                'last_attempted_at' => null,
                'reserved_at' => null,
                // UTC, exactly as DatabaseAuditOutbox stores it — well past the default 120s threshold.
                'created_at' => Carbon::now('UTC')->subMinutes(10),
                'updated_at' => Carbon::now('UTC')->subMinutes(10),
            ]);

            $exitCode = Artisan::call('swarm:health');

            expect($exitCode)->toBe(0);
            expect(Artisan::output())
                ->toContain('Audit outbox staleness')
                ->toContain('warning')
                ->toContain('unclaimed pending row(s) aging')
                ->not->toContain('relay appears active');
        } finally {
            date_default_timezone_set($originalTz);
        }
    });

    test('recent unclaimed audit rows stay ok (relay running normally)', function (): void {
        // Freshly enqueued, not yet claimed — normal between relay runs.
        DB::table('swarm_audit_outbox')->insert([
            'category' => 'run.failed',
            'run_id' => 'r-fresh',
            'payload' => '{}',
            'attempts' => 0,
            'status' => 'pending',
            'last_error' => null,
            'last_attempted_at' => null,
            'reserved_at' => null,
            'created_at' => now()->subSeconds(5),
            'updated_at' => now()->subSeconds(5),
        ]);

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())
            ->toContain('Audit outbox staleness')
            ->toContain('relay appears active');
    });

    test('staleness warning reports stale-reserved and aged-unclaimed signals together (F4)', function (): void {
        // One row the relay claimed then abandoned (reservation expired)...
        DB::table('swarm_audit_outbox')->insert([
            'category' => 'run.failed',
            'run_id' => 'r-stale-reserved',
            'payload' => '{}',
            'attempts' => 1,
            'status' => 'pending',
            'last_error' => null,
            'last_attempted_at' => now()->subMinutes(5),
            'reserved_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
        // ...and one the relay never claimed, aged past the warning threshold.
        DB::table('swarm_audit_outbox')->insert([
            'category' => 'run.failed',
            'run_id' => 'r-aged-unclaimed',
            'payload' => '{}',
            'attempts' => 0,
            'status' => 'pending',
            'last_error' => null,
            'last_attempted_at' => null,
            'reserved_at' => null,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        // Both signals must appear in the single joined warning detail.
        expect(Artisan::output())
            ->toContain('Audit outbox staleness')
            ->toContain('warning')
            ->toContain('stale reservations')
            ->toContain('unclaimed pending row(s) aging');
    });

    test('dead-letter audit rows produce a warning status (Part 11 compliance signal)', function (): void {
        DB::table('swarm_audit_outbox')->insert([
            'category' => 'run.failed',
            'run_id' => 'r-dead',
            'payload' => '{}',
            'attempts' => 5,
            'status' => 'dead_letter',
            'last_error' => 'permanent failure',
            'last_attempted_at' => now()->subMinutes(1),
            'reserved_at' => null,
            'created_at' => now()->subMinutes(1),
            'updated_at' => now()->subMinutes(1),
        ]);

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())
            ->toContain('dead-letter')
            ->toContain('warning')
            ->toContain('1 dead-letter row');
    });

    test('healthy audit outbox returns ok statuses', function (): void {
        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        $output = Artisan::output();
        expect($output)
            ->toContain('Audit outbox staleness')
            ->toContain('no pending rows')
            ->toContain('Audit outbox dead-letter')
            ->toContain('no dead-letter rows');
    });
});

// ---------------------------------------------------------------------------
// Fix 1 — ok/exit-code consistency (#247)
// ---------------------------------------------------------------------------

describe('json ok mirrors exit code (fix #247)', function (): void {
    beforeEach(function (): void {
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.capture.active_context', true);
    });

    test('healthy --durable --json run emits ok:true and exits 0', function (): void {
        $exitCode = Artisan::call('swarm:health', ['--durable' => true, '--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        expect($exitCode)->toBe(0)
            ->and($payload['ok'])->toBeTrue();
    });

    test('run with only warning rows emits ok:true and exits 0', function (): void {
        // Insert a stale durable outbox row so the staleness check returns 'warning'.
        DB::statement('PRAGMA foreign_keys = OFF');
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
        DB::statement('PRAGMA foreign_keys = ON');

        $exitCode = Artisan::call('swarm:health', ['--durable' => true, '--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        expect($exitCode)->toBe(0)
            ->and($payload['ok'])->toBeTrue();
    });

    test('run with a failed row emits ok:false and exits 1', function (): void {
        config()->set('swarm.capture.active_context', false);

        $exitCode = Artisan::call('swarm:health', ['--durable' => true, '--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        expect($exitCode)->toBe(1)
            ->and($payload['ok'])->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Fix 2 — policy-aware audit outbox scoping (#247)
// ---------------------------------------------------------------------------

describe('policy-aware audit outbox scoping (fix #247)', function (): void {
    beforeEach(function (): void {
        config()->set('swarm.persistence.driver', 'database');
    });

    test('swallow policy with missing outbox table emits note and exits 0', function (): void {
        config()->set('swarm.audit.failure_policy', 'swallow');
        config()->set('swarm.tables.audit_outbox', 'nonexistent_audit_outbox_xyz');

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        $output = Artisan::output();
        expect($output)
            ->toContain('Audit outbox')
            ->toContain('note')
            ->not->toContain('failed');
    });

    test('log policy with missing outbox table emits note and exits 0', function (): void {
        config()->set('swarm.audit.failure_policy', 'log');
        config()->set('swarm.tables.audit_outbox', 'nonexistent_audit_outbox_xyz');

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        $output = Artisan::output();
        expect($output)
            ->toContain('Audit outbox')
            ->toContain('note')
            ->not->toContain('failed');
    });

    test('halt policy with missing outbox table emits note and exits 0', function (): void {
        config()->set('swarm.audit.failure_policy', 'halt');
        config()->set('swarm.tables.audit_outbox', 'nonexistent_audit_outbox_xyz');

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        $output = Artisan::output();
        expect($output)
            ->toContain('Audit outbox')
            ->toContain('note')
            ->not->toContain('failed');
    });

    test('queue policy (default) with missing outbox table emits failed and exits 1', function (): void {
        config()->set('swarm.audit.failure_policy', 'queue');
        config()->set('swarm.tables.audit_outbox', 'nonexistent_audit_outbox_xyz');

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(1);
        expect(Artisan::output())
            ->toContain('Audit outbox')
            ->toContain('failed');
    });

    test('dead_letter policy with missing outbox table emits failed and exits 1', function (): void {
        config()->set('swarm.audit.failure_policy', 'dead_letter');
        config()->set('swarm.tables.audit_outbox', 'nonexistent_audit_outbox_xyz');

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(1);
        expect(Artisan::output())
            ->toContain('Audit outbox')
            ->toContain('failed');
    });

    test('note row from non-outbox policy does not flip ok to false in --json output', function (): void {
        config()->set('swarm.audit.failure_policy', 'swallow');

        $exitCode = Artisan::call('swarm:health', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        expect($exitCode)->toBe(0)
            ->and($payload['ok'])->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// v0.22.0 — governed-by-default checks (guardrails / audit sink / capture policy)
// ---------------------------------------------------------------------------

describe('guardrail resolution check', function (): void {
    test('reports ok with an empty-config message when no guardrails are configured', function (): void {
        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())
            ->toContain('Guardrails')
            ->toContain('no global guardrails configured');
    });

    test('reports ok when a configured guardrail ref resolves from the container', function (): void {
        config()->set('swarm.guardrails.input', [stdClass::class]);

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())
            ->toContain('Guardrails')
            ->toContain('global guardrail ref(s) resolve');
    });

    test('reports failed and exits 1 when a configured guardrail ref is not resolvable', function (): void {
        config()->set('swarm.guardrails.step', ['Not\\A\\Real\\GuardrailClass']);

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(1);
        expect(Artisan::output())
            ->toContain('Guardrails')
            ->toContain('failed')
            ->toContain('not resolvable');
    });
});

describe('audit sink check', function (): void {
    test('reports a note (not a failure) when the sink is the default NoOp', function (): void {
        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())
            ->toContain('Audit sink')
            ->toContain('NoOpSwarmAuditSink')
            ->toContain('swarm:install:audit');
    });

    test('reports ok when a real SwarmAuditSink is bound', function (): void {
        app()->singleton(SwarmAuditSink::class, SwarmHealthRecordingAuditSink::class);

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())
            ->toContain('Audit sink')
            ->toContain('SwarmHealthRecordingAuditSink');
    });
});

describe('capture policy check', function (): void {
    test('reports ok when the default CapturePolicy resolves', function (): void {
        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())
            ->toContain('Capture policy')
            ->toContain('CapturePolicy resolves');
    });

    test('reports failed and exits 1 when the CapturePolicy binding cannot be resolved', function (): void {
        app()->bind(CapturePolicy::class, function (): CapturePolicy {
            throw new RuntimeException('capture policy binding is broken');
        });

        $exitCode = Artisan::call('swarm:health');

        expect($exitCode)->toBe(1);
        expect(Artisan::output())
            ->toContain('Capture policy')
            ->toContain('could not be resolved');
    });
});

describe('governance checks in --json output', function (): void {
    test('the three governance checks appear with the standard shape in --json', function (): void {
        $exitCode = Artisan::call('swarm:health', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $components = array_column($payload['checks'], 'component');

        expect($exitCode)->toBe(0)
            ->and($payload['ok'])->toBeTrue()
            ->and($components)->toContain('Guardrails')
            ->and($components)->toContain('Audit sink')
            ->and($components)->toContain('Capture policy');
    });

    test('the three governance checks are skipped under --audit (audit-outbox scope only)', function (): void {
        $output = Artisan::call('swarm:health', ['--audit' => true]);

        expect($output)->toBe(0);
        expect(Artisan::output())
            ->not->toContain('Guardrails')
            ->not->toContain('Capture policy');
    });
});
