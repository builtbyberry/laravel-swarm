<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Pulse\Livewire\AuditOutbox as AuditOutboxCard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    app()->forgetInstance(AuditOutbox::class);
});

function seedCardOutboxRow(array $attributes = []): int
{
    $now = Carbon::now('UTC');

    return (int) DB::table('swarm_audit_outbox')->insertGetId(array_merge([
        'category' => 'run.failed',
        'run_id' => 'r-card-test',
        'payload' => json_encode(['run_id' => 'r-card-test', 'category' => 'run.failed']),
        'attempts' => 0,
        'status' => 'pending',
        'last_error' => null,
        'last_attempted_at' => null,
        'reserved_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $attributes));
}

test('audit outbox card renders zero counts with no alarm against an empty outbox', function (): void {
    Livewire::withoutLazyLoading();

    Livewire::test('swarm.audit-outbox')
        ->assertSee('Swarm Audit Outbox')
        ->assertSee('Dead-letter')
        ->assertSee('Pending')
        ->assertSee('Stale pending')
        ->assertDontSee('attention')
        ->assertDontSee('relay may not be running')
        ->assertSee('indefinite');
});

test('audit outbox card surfaces pending, dead-letter, and stale counts and raises alarm for dead-letter rows', function (): void {
    config()->set('swarm.durable.relay.reservation_timeout_seconds', 60);

    $now = Carbon::now('UTC');
    $staleReservedAt = $now->copy()->subSeconds(60 * 2 + 10);

    seedCardOutboxRow(['status' => 'pending']);
    seedCardOutboxRow(['status' => 'pending']);
    seedCardOutboxRow(['status' => 'pending', 'reserved_at' => $staleReservedAt]);
    seedCardOutboxRow(['status' => 'dead_letter']);
    seedCardOutboxRow(['status' => 'dead_letter']);

    Livewire::withoutLazyLoading();

    Livewire::test('swarm.audit-outbox')
        ->assertSee('Swarm Audit Outbox')
        ->assertSeeInOrder(['Dead-letter', '2'])
        ->assertSeeInOrder(['Pending', '3'])
        ->assertSeeInOrder(['Stale pending', '1'])
        ->assertSee('attention')
        ->assertSee('relay may not be running');
});

test('audit outbox card honours configured dead-letter retention setting', function (): void {
    config()->set('swarm.audit.outbox.dead_letter_retention_days', 14);

    Livewire::withoutLazyLoading();

    Livewire::test('swarm.audit-outbox')
        ->assertSee('14 days');
});

test('audit outbox card renders unavailable state on cache driver without querying the database', function (): void {
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(AuditOutbox::class);

    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();

    Livewire::withoutLazyLoading();

    Livewire::test('swarm.audit-outbox')
        ->assertSee('Audit outbox unavailable on cache persistence driver.');

    $queries = DB::connection()->getQueryLog();
    $outboxQueries = array_filter(
        $queries,
        fn (array $query): bool => str_contains((string) $query['query'], 'swarm_audit_outbox'),
    );

    expect($outboxQueries)->toBe([]);
});

test('audit outbox card resolveSnapshot reports correct oldest ages and counts', function (): void {
    $now = Carbon::now('UTC');

    DB::table('swarm_audit_outbox')->insert([
        [
            'category' => 'run.failed',
            'run_id' => 'r1',
            'payload' => json_encode(['run_id' => 'r1']),
            'attempts' => 0,
            'status' => 'pending',
            'last_error' => null,
            'last_attempted_at' => null,
            'reserved_at' => null,
            'created_at' => $now->copy()->subHours(3),
            'updated_at' => $now->copy()->subHours(3),
        ],
        [
            'category' => 'run.failed',
            'run_id' => 'r2',
            'payload' => json_encode(['run_id' => 'r2']),
            'attempts' => 3,
            'status' => 'dead_letter',
            'last_error' => 'boom',
            'last_attempted_at' => $now->copy()->subDays(2),
            'reserved_at' => null,
            'created_at' => $now->copy()->subDays(2),
            'updated_at' => $now->copy()->subDays(2),
        ],
    ]);

    $card = new class extends AuditOutboxCard
    {
        public function snapshot(): object
        {
            return $this->resolveSnapshot();
        }
    };

    $snapshot = $card->snapshot();

    expect($snapshot->pending)->toBe(1);
    expect($snapshot->deadLetter)->toBe(1);
    expect($snapshot->stalePending)->toBe(0);
    expect($snapshot->oldestPendingAge)->not->toBeNull();
    expect($snapshot->oldestDeadLetterAge)->not->toBeNull();
});
