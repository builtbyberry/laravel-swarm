<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    app()->forgetInstance(AuditOutbox::class);
});

function seedAuditOutboxRow(string $status, ?Carbon $lastAttemptedAt = null): int
{
    return (int) DB::table('swarm_audit_outbox')->insertGetId([
        'category' => 'run.failed',
        'run_id' => 'r-test',
        'payload' => json_encode(['run_id' => 'r-test', 'category' => 'run.failed']),
        'attempts' => 1,
        'status' => $status,
        'last_error' => null,
        'last_attempted_at' => $lastAttemptedAt,
        'reserved_at' => null,
        'created_at' => Carbon::now('UTC'),
        'updated_at' => Carbon::now('UTC'),
    ]);
}

test('swarm:prune leaves audit outbox dead_letter rows alone when retention is null (default)', function (): void {
    config()->set('swarm.audit.outbox.dead_letter_retention_days', null);

    seedAuditOutboxRow('dead_letter', Carbon::now('UTC')->subDays(365));
    seedAuditOutboxRow('dead_letter', Carbon::now('UTC')->subDays(30));

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_audit_outbox')->count())->toBe(2);
});

test('swarm:prune removes only dead_letter rows older than the configured retention window', function (): void {
    config()->set('swarm.audit.outbox.dead_letter_retention_days', 30);

    $oldId = seedAuditOutboxRow('dead_letter', Carbon::now('UTC')->subDays(45));
    $freshId = seedAuditOutboxRow('dead_letter', Carbon::now('UTC')->subDays(5));

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_audit_outbox')->where('id', $oldId)->exists())->toBeFalse();
    expect(DB::table('swarm_audit_outbox')->where('id', $freshId)->exists())->toBeTrue();
});

test('swarm:prune never removes pending or reserved audit outbox rows regardless of age or retention setting', function (): void {
    config()->set('swarm.audit.outbox.dead_letter_retention_days', 1);

    seedAuditOutboxRow('pending', Carbon::now('UTC')->subYear());
    seedAuditOutboxRow('pending', Carbon::now('UTC')->subDays(2));

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_audit_outbox')->where('status', 'pending')->count())->toBe(2);
});

test('swarm:prune --dry-run reports audit outbox dead-letter count without deleting', function (): void {
    config()->set('swarm.audit.outbox.dead_letter_retention_days', 7);

    seedAuditOutboxRow('dead_letter', Carbon::now('UTC')->subDays(30));
    seedAuditOutboxRow('dead_letter', Carbon::now('UTC')->subDays(30));

    Artisan::call('swarm:prune', ['--dry-run' => true]);

    expect(DB::table('swarm_audit_outbox')->count())->toBe(2);
});

test('swarm:prune respects swarm.retention.prevent_prune for the audit outbox lane', function (): void {
    config()->set('swarm.audit.outbox.dead_letter_retention_days', 1);
    config()->set('swarm.retention.prevent_prune', true);

    seedAuditOutboxRow('dead_letter', Carbon::now('UTC')->subDays(30));

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_audit_outbox')->count())->toBe(1);
});
