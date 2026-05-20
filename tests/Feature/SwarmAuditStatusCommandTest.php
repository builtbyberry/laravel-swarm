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

function seedAuditStatusRow(array $attributes): int
{
    $now = Carbon::now('UTC');

    return (int) DB::table('swarm_audit_outbox')->insertGetId(array_merge([
        'category' => 'run.failed',
        'run_id' => 'r-test',
        'payload' => json_encode(['run_id' => 'r-test', 'category' => 'run.failed']),
        'attempts' => 0,
        'status' => 'pending',
        'last_error' => null,
        'last_attempted_at' => null,
        'reserved_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $attributes));
}

test('swarm:audit:status reports zeroed summary against an empty outbox', function (): void {
    $exitCode = Artisan::call('swarm:audit:status', ['--json' => true]);

    expect($exitCode)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['ok'])->toBeTrue();
    expect($payload['store'])->toBe('database');
    expect($payload['available'])->toBeTrue();
    expect($payload['counts'])->toBe([
        'pending' => 0,
        'reserved' => 0,
        'stale_reserved' => 0,
        'dead_letter' => 0,
    ]);
    expect($payload['age_distribution']['pending'])->toBe([
        'lt_1h' => 0,
        'h1_24' => 0,
        'd1_7' => 0,
        'gt_7d' => 0,
    ]);
    expect($payload['top_dead_letter_categories'])->toBe([]);
    expect($payload['oldest']['pending'])->toBeNull();
    expect($payload['oldest']['dead_letter'])->toBeNull();
    expect($payload['retention']['dead_letter_retention_days'])->toBeNull();
    expect($payload['retention']['next_prune_count'])->toBe(0);
});

test('swarm:audit:status counts pending, reserved, stale_reserved, and dead_letter', function (): void {
    config()->set('swarm.durable.relay.reservation_timeout_seconds', 60);
    $now = Carbon::now('UTC');

    seedAuditStatusRow(['status' => 'pending', 'reserved_at' => null]);
    seedAuditStatusRow(['status' => 'pending', 'reserved_at' => null]);
    seedAuditStatusRow(['status' => 'pending', 'reserved_at' => $now->copy()->subSeconds(30)]);
    seedAuditStatusRow(['status' => 'pending', 'reserved_at' => $now->copy()->subSeconds(300)]);
    seedAuditStatusRow(['status' => 'dead_letter']);
    seedAuditStatusRow(['status' => 'dead_letter']);
    seedAuditStatusRow(['status' => 'dead_letter']);

    Artisan::call('swarm:audit:status', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['counts'])->toBe([
        'pending' => 2,
        'reserved' => 2,
        'stale_reserved' => 1,
        'dead_letter' => 3,
    ]);
});

test('swarm:audit:status buckets pending and dead_letter rows by created_at age', function (): void {
    $now = Carbon::now('UTC');

    seedAuditStatusRow(['status' => 'pending', 'created_at' => $now->copy()->subMinutes(10)]);
    seedAuditStatusRow(['status' => 'pending', 'created_at' => $now->copy()->subHours(4)]);
    seedAuditStatusRow(['status' => 'pending', 'created_at' => $now->copy()->subDays(3)]);
    seedAuditStatusRow(['status' => 'pending', 'created_at' => $now->copy()->subDays(20)]);

    seedAuditStatusRow(['status' => 'dead_letter', 'created_at' => $now->copy()->subMinutes(5)]);
    seedAuditStatusRow(['status' => 'dead_letter', 'created_at' => $now->copy()->subDays(2)]);
    seedAuditStatusRow(['status' => 'dead_letter', 'created_at' => $now->copy()->subDays(40)]);
    seedAuditStatusRow(['status' => 'dead_letter', 'created_at' => $now->copy()->subDays(40)]);

    Artisan::call('swarm:audit:status', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['age_distribution']['pending'])->toBe([
        'lt_1h' => 1,
        'h1_24' => 1,
        'd1_7' => 1,
        'gt_7d' => 1,
    ]);
    expect($payload['age_distribution']['dead_letter'])->toBe([
        'lt_1h' => 1,
        'h1_24' => 0,
        'd1_7' => 1,
        'gt_7d' => 2,
    ]);
});

test('swarm:audit:status reports top dead_letter categories sorted by count desc then category', function (): void {
    foreach (range(1, 4) as $_) {
        seedAuditStatusRow(['status' => 'dead_letter', 'category' => 'run.failed']);
    }
    foreach (range(1, 2) as $_) {
        seedAuditStatusRow(['status' => 'dead_letter', 'category' => 'tool.invoked']);
    }
    seedAuditStatusRow(['status' => 'dead_letter', 'category' => 'guardrail.blocked']);
    seedAuditStatusRow(['status' => 'dead_letter', 'category' => 'command.relay']);
    seedAuditStatusRow(['status' => 'dead_letter', 'category' => 'agent.invoked']);
    seedAuditStatusRow(['status' => 'dead_letter', 'category' => 'something.else']);

    seedAuditStatusRow(['status' => 'pending', 'category' => 'should.not.appear']);

    Artisan::call('swarm:audit:status', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $categories = array_map(fn (array $row): string => $row['category'], $payload['top_dead_letter_categories']);
    $counts = array_map(fn (array $row): int => $row['count'], $payload['top_dead_letter_categories']);

    expect($categories[0])->toBe('run.failed');
    expect($counts[0])->toBe(4);
    expect($categories[1])->toBe('tool.invoked');
    expect($counts[1])->toBe(2);
    expect(count($payload['top_dead_letter_categories']))->toBe(5);

    $tail = array_slice($categories, 2);
    expect($tail)->toBe(['agent.invoked', 'command.relay', 'guardrail.blocked']);
});

test('swarm:audit:status reports oldest pending and dead_letter row id + age', function (): void {
    $now = Carbon::now('UTC');

    $newerPending = seedAuditStatusRow(['status' => 'pending', 'created_at' => $now->copy()->subMinutes(5)]);
    $olderPending = seedAuditStatusRow(['status' => 'pending', 'created_at' => $now->copy()->subDays(3)]);

    $newerDead = seedAuditStatusRow(['status' => 'dead_letter', 'created_at' => $now->copy()->subHours(2)]);
    $olderDead = seedAuditStatusRow(['status' => 'dead_letter', 'created_at' => $now->copy()->subDays(10)]);

    Artisan::call('swarm:audit:status', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['oldest']['pending']['id'])->toBe($olderPending);
    expect($payload['oldest']['pending']['age'])->toBeString();
    expect($payload['oldest']['dead_letter']['id'])->toBe($olderDead);
    expect($payload['oldest']['dead_letter']['age'])->toBeString();

    expect($payload['oldest']['pending']['id'])->not->toBe($newerPending);
    expect($payload['oldest']['dead_letter']['id'])->not->toBe($newerDead);
});

test('swarm:audit:status reports retention next_prune_count under the configured window', function (): void {
    config()->set('swarm.audit.outbox.dead_letter_retention_days', 7);

    $now = Carbon::now('UTC');

    seedAuditStatusRow(['status' => 'dead_letter', 'last_attempted_at' => $now->copy()->subDays(10)]);
    seedAuditStatusRow(['status' => 'dead_letter', 'last_attempted_at' => $now->copy()->subDays(30)]);
    seedAuditStatusRow(['status' => 'dead_letter', 'last_attempted_at' => $now->copy()->subDays(2)]);
    seedAuditStatusRow(['status' => 'dead_letter', 'last_attempted_at' => null]);

    Artisan::call('swarm:audit:status', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['retention']['dead_letter_retention_days'])->toBe(7);
    expect($payload['retention']['next_prune_count'])->toBe(2);
});

test('swarm:audit:status reports zero next_prune_count when retention is unconfigured', function (): void {
    config()->set('swarm.audit.outbox.dead_letter_retention_days', null);

    seedAuditStatusRow(['status' => 'dead_letter', 'last_attempted_at' => Carbon::now('UTC')->subYear()]);

    Artisan::call('swarm:audit:status', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['retention']['dead_letter_retention_days'])->toBeNull();
    expect($payload['retention']['next_prune_count'])->toBe(0);
});

test('swarm:audit:status --json shape stays stable', function (): void {
    seedAuditStatusRow(['status' => 'pending']);
    seedAuditStatusRow(['status' => 'dead_letter']);

    Artisan::call('swarm:audit:status', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($payload))->toBe([
        'ok',
        'store',
        'available',
        'counts',
        'age_distribution',
        'top_dead_letter_categories',
        'oldest',
        'retention',
    ]);
    expect(array_keys($payload['counts']))->toBe(['pending', 'reserved', 'stale_reserved', 'dead_letter']);
    expect(array_keys($payload['age_distribution']))->toBe(['pending', 'dead_letter']);
    expect(array_keys($payload['age_distribution']['pending']))->toBe(['lt_1h', 'h1_24', 'd1_7', 'gt_7d']);
    expect(array_keys($payload['oldest']))->toBe(['pending', 'dead_letter']);
    expect(array_keys($payload['retention']))->toBe(['dead_letter_retention_days', 'next_prune_count']);
});

test('swarm:audit:status degrades on cache persistence without touching the database', function (): void {
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(AuditOutbox::class);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $exitCode = Artisan::call('swarm:audit:status', ['--json' => true]);

    expect($exitCode)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($payload)->toBe([
        'ok' => true,
        'store' => 'cache',
        'available' => false,
    ]);

    $queries = collect(DB::getQueryLog())
        ->reject(fn (array $entry): bool => str_contains(strtolower($entry['query']), 'sqlite_master'))
        ->filter(fn (array $entry): bool => str_contains(strtolower($entry['query']), 'swarm_audit_outbox'));

    expect($queries)->toBeEmpty();

    DB::disableQueryLog();
});

test('swarm:audit:status non-JSON output renders Laravel-native sections without errors', function (): void {
    seedAuditStatusRow(['status' => 'pending']);
    seedAuditStatusRow(['status' => 'dead_letter', 'category' => 'run.failed']);

    $exitCode = Artisan::call('swarm:audit:status');

    expect($exitCode)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('Audit outbox summary');
    expect($output)->toContain('Age distribution');
    expect($output)->toContain('Top dead-letter categories');
    expect($output)->toContain('Oldest rows');
    expect($output)->toContain('Retention');
});
