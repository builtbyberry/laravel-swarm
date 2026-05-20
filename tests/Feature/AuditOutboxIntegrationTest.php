<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\CountingThrowingSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issue #39 — integration coverage for the v0.5 audit outbox flow.
 *
 * Three scenarios sanity-checked manually before v0.5.0 tag and now automated:
 *
 *   1. DB-backed app, failing sink → outbox row → replay → dead_letter
 *      (covers the full happy and unhappy paths the operator sees in prod).
 *   2. Cache-backed app, failing sink → log-and-swallow
 *      (proves the NoOp fallback never touches the DB).
 *   3. Cross-lane `swarm:relay` drains both durable and audit lanes in one pass
 *      and reports both counters in the same `command.relay` audit emit.
 */
function configureDatabasePersistence(): void
{
    config()->set('swarm.persistence.driver', 'database');

    foreach ([
        AuditOutbox::class,
        SwarmAuditDispatcher::class,
        ContextStore::class,
        ArtifactRepository::class,
        RunHistoryStore::class,
        DurableRunStore::class,
        SwarmRunner::class,
        DurableSwarmManager::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

// ---------------------------------------------------------------------------
// Scenario 1 — DB-backed app: failing sink → outbox → replay → dead_letter
// ---------------------------------------------------------------------------

it('persists a pending outbox row when the bound sink throws under failure_policy=queue', function (): void {
    configureDatabasePersistence();

    $sink = new CountingThrowingSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.failed', [
        'run_id' => 'r-queued',
        'category' => 'run.failed',
    ]);

    expect($sink->attempts)->toBe(1);

    $row = DB::table('swarm_audit_outbox')->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe('pending');
    expect($row->run_id)->toBe('r-queued');
    // enqueue() stores the freshly-routed row with attempts=0; the first drain
    // is what increments attempts. The sink failure that put the row here does
    // not count against max_attempts.
    expect($row->attempts)->toBe(0);
});

it('drives a queued outbox row from pending through replay to deletion when a passing sink takes over', function (): void {
    configureDatabasePersistence();

    // Initial failing emit routes the payload to the outbox.
    $failingSink = new CountingThrowingSink;
    app()->instance(SwarmAuditSink::class, $failingSink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.failed', [
        'run_id' => 'r-replay',
        'category' => 'run.failed',
    ]);

    expect(DB::table('swarm_audit_outbox')->where('status', 'pending')->count())->toBe(1);

    // First drain still sees the failing sink: attempts bumps, row remains pending.
    $outbox = app(AuditOutbox::class);

    $firstResult = $outbox->drain();
    expect($firstResult->failed)->toBe(1);
    expect($firstResult->replayed)->toBe(0);

    $row = DB::table('swarm_audit_outbox')->where('run_id', 'r-replay')->first();
    expect($row->status)->toBe('pending');
    expect($row->attempts)->toBe(1);

    // Swap to a passing sink; next drain replays and deletes the row.
    $passingSink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $passingSink);
    app()->forgetInstance(AuditOutbox::class);

    $secondResult = app(AuditOutbox::class)->drain();
    expect($secondResult->replayed)->toBe(1);
    expect($secondResult->failed)->toBe(0);
    expect(DB::table('swarm_audit_outbox')->where('run_id', 'r-replay')->count())->toBe(0);

    $records = $passingSink->recordsForCategory('run.failed');
    expect($records)->toHaveCount(1);
    expect($records[0]['run_id'])->toBe('r-replay');
});

it('transitions a queued outbox row to dead_letter and logs the transition once max_attempts is exceeded', function (): void {
    configureDatabasePersistence();
    config()->set('swarm.audit.outbox.max_attempts', 2);

    Log::spy();

    $failingSink = new CountingThrowingSink;
    app()->instance(SwarmAuditSink::class, $failingSink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.failed', [
        'run_id' => 'r-dead',
        'category' => 'run.failed',
    ]);

    // Drain max_attempts + 1 times: first drain → attempts=1, second drain
    // hits the cap and transitions the row to dead_letter.
    $outbox = app(AuditOutbox::class);

    $first = $outbox->drain();
    expect($first->failed)->toBe(1);
    expect($first->deadLettered)->toBe(0);

    $second = $outbox->drain();
    expect($second->failed)->toBe(0);
    expect($second->deadLettered)->toBe(1);

    $row = DB::table('swarm_audit_outbox')->where('run_id', 'r-dead')->first();
    expect($row->status)->toBe('dead_letter');
    expect($row->attempts)->toBe(2);

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'dead_letter')
                && ($context['run_id'] ?? null) === 'r-dead'
                && ($context['category'] ?? null) === 'run.failed'
                && ($context['attempts'] ?? null) === 2;
        })
        ->once();
});

// ---------------------------------------------------------------------------
// Scenario 2 — Cache-backed app: failing sink → log-and-swallow, no DB write
// ---------------------------------------------------------------------------

it('logs a warning and never writes to the outbox table when the persistence driver is cache', function (): void {
    // Persistence driver stays at the package default (cache) — NoOpAuditOutbox
    // is bound, isAvailable() is false, and routeToOutbox() must fall through
    // to log-and-swallow without attempting any DB write.
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    Log::spy();

    $failingSink = new CountingThrowingSink;
    app()->instance(SwarmAuditSink::class, $failingSink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.failed', [
        'run_id' => 'r-cache',
        'category' => 'run.failed',
    ]);

    expect($failingSink->attempts)->toBe(1);
    expect(DB::table('swarm_audit_outbox')->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'outbox is unavailable')
                && ($context['category'] ?? null) === 'run.failed'
                && ($context['decision'] ?? null) === 'queue';
        })
        ->once();
});

// ---------------------------------------------------------------------------
// Scenario 3 — `swarm:relay` (no --type) drains both lanes in one pass
// ---------------------------------------------------------------------------

it('drains durable and audit lanes in a single swarm:relay invocation and reports both counters', function (): void {
    configureDatabasePersistence();
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    // Recording sink so we can inspect the command.relay envelope.
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    // Seed the durable lane: a stuck step dispatch tied to a real durable run.
    $response = FakeSequentialSwarm::make()->dispatchDurable('cross-lane-task');
    app(DurableOutbox::class)->enqueueStep($response->runId, 1, null, null);
    expect(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(1);

    // Seed the audit lane: a pending row ready to replay through the recording sink.
    app(AuditOutbox::class)->enqueue('run.failed', [
        'run_id' => 'r-cross-lane',
        'category' => 'run.failed',
    ]);
    expect(DB::table('swarm_audit_outbox')->where('status', 'pending')->count())->toBe(1);

    $sink->reset();

    $exitCode = Artisan::call('swarm:relay');

    expect($exitCode)->toBe(0);
    expect(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
    expect(DB::table('swarm_audit_outbox')->where('run_id', 'r-cross-lane')->count())->toBe(0);

    // The replayed audit row goes through the recording sink alongside the
    // command.relay emit — pluck the command.relay record specifically.
    $relayRecords = $sink->recordsForCategory('command.relay');
    expect($relayRecords)->toHaveCount(1);

    $relay = $relayRecords[0];
    expect($relay['dispatched_count'])->toBeGreaterThan(0);
    expect($relay['audit_replayed_count'])->toBeGreaterThan(0);
    expect($relay['audit_dead_lettered_count'])->toBe(0);
    expect($relay['status'])->toBe('dispatched');

    // The replayed audit payload also made it through the sink in the same drain.
    expect($sink->hasCategory('run.failed'))->toBeTrue();
});
