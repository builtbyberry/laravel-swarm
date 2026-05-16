<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// swarm:recover — warnIfRelayNotRunning() coverage
// ---------------------------------------------------------------------------

describe('warnIfRelayNotRunning', function (): void {
    beforeEach(function (): void {
        config()->set('swarm.persistence.driver', 'database');
        // Disable FK constraints so outbox rows can be inserted without a
        // matching parent row in swarm_durable_runs.
        DB::statement('PRAGMA foreign_keys = OFF');
    });

    afterEach(function (): void {
        DB::statement('PRAGMA foreign_keys = ON');
    });

    test('warning fires when there are stale unresolved outbox rows', function (): void {
        $reservationTimeout = (int) config('swarm.durable.relay.reservation_timeout_seconds', 60);

        // Insert a row old enough to exceed 2 × reservation_timeout_seconds with
        // reserved_at = null so it is both unclaimed and stale.
        DB::table('swarm_durable_outbox')->insert([
            'run_id' => (string) Str::uuid(),
            'dispatch_type' => 'step',
            'payload' => '{}',
            'queue_connection' => null,
            'queue_name' => null,
            'available_at' => now()->subSeconds($reservationTimeout * 2 + 30),
            'reserved_at' => null,
            'created_at' => now()->subSeconds($reservationTimeout * 2 + 30),
        ]);

        $exitCode = Artisan::call('swarm:recover');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('outbox row(s) aging');
        expect($output)->toContain('is swarm:relay scheduled?');
    });

    test('no warning when the outbox table is empty', function (): void {
        // Confirm the table exists but has no rows.
        expect(DB::table('swarm_durable_outbox')->count())->toBe(0);

        $exitCode = Artisan::call('swarm:recover');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('is swarm:relay scheduled?');
    });

    test('no warning when persistence driver is not database', function (): void {
        config()->set('swarm.persistence.driver', 'cache');

        $exitCode = Artisan::call('swarm:recover');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('is swarm:relay scheduled?');
    });

    test('command exits successfully when the outbox table does not exist', function (): void {
        // Point the config at a non-existent table; the method must swallow the
        // exception and never let it crash the command.
        config()->set('swarm.tables.durable_outbox', 'nonexistent_swarm_outbox_xyz');

        $exitCode = Artisan::call('swarm:recover');

        expect($exitCode)->toBe(0);
    });
});
