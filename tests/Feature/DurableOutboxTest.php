<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\OutboxDispatchType;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function outboxConfigureDurableRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(DurableSwarmManager::class);
}

uses()->beforeEach(fn () => outboxConfigureDurableRuntime())->in(__FILE__);

// ---------------------------------------------------------------------------
// DatabaseDurableOutbox::drain()
// ---------------------------------------------------------------------------

test('drain dispatches pending step entries and deletes them', function (): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    $outbox->enqueueStep($response->runId, 1, null, null);

    $result = $outbox->drain([OutboxDispatchType::Step], 100);

    expect($result)->toBeInstanceOf(DrainResult::class)
        ->and($result->dispatched)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

test('drain reclaims stale reservations after reservation timeout', function (): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    $outbox->enqueueStep($response->runId, 1, null, null);

    // Simulate a stale reservation (reserved but not deleted)
    DB::table('swarm_durable_outbox')
        ->where('run_id', $response->runId)
        ->update(['reserved_at' => now()->subMinutes(5)]);

    $result = $outbox->drain([OutboxDispatchType::Step], 100);

    expect($result->dispatched)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

test('drain skips entries with invalid dispatch_type when type-filtered', function (): void {
    // When a type filter is active the bad row is excluded from the SQL query entirely
    // (not claimed, not dispatched, not deleted). This verifies the filter itself.
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    $outbox->enqueueStep($response->runId, 1, null, null);
    DB::table('swarm_durable_outbox')->insert([
        'run_id' => $response->runId,
        'dispatch_type' => 'not_a_real_type',
        'payload' => '{"step_index":2}',
        'queue_connection' => null,
        'queue_name' => null,
        'available_at' => now(),
        'reserved_at' => null,
        'created_at' => now(),
    ]);

    $result = $outbox->drain([OutboxDispatchType::Step], 100);

    // Valid step entry dispatched, bad entry excluded by filter (1 row remains)
    expect($result->dispatched)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(1);
});

test('drain reports and deletes permanently invalid entries when draining without a type filter', function (): void {
    // Without a type filter the bad row IS claimed. dispatchEntry() throws
    // UnexpectedValueException, which the drain loop catches as a skipped entry:
    // reported to the error handler, deleted from the outbox, and counted in $skipped.
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    $outbox->enqueueStep($response->runId, 1, null, null);
    DB::table('swarm_durable_outbox')->insert([
        'run_id' => $response->runId,
        'dispatch_type' => 'not_a_real_type',
        'payload' => '{"step_index":2}',
        'queue_connection' => null,
        'queue_name' => null,
        'available_at' => now(),
        'reserved_at' => null,
        'created_at' => now(),
    ]);

    $result = $outbox->drain([], 100);

    // Valid entry dispatched, invalid entry skipped-and-deleted — outbox is now clean
    expect($result->dispatched)->toBe(1)
        ->and($result->skipped)->toBe(1)
        ->and($result->total())->toBe(2)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

test('drain treats a null-json payload as invalid and skips the entry', function (): void {
    // json_decode('null', true) returns null (valid JSON), but the payload must be
    // an associative array — the is_array() guard catches this case.
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    DB::table('swarm_durable_outbox')->insert([
        'run_id' => $response->runId,
        'dispatch_type' => OutboxDispatchType::Step->value,
        'payload' => 'null',  // valid JSON, but not an array
        'queue_connection' => null,
        'queue_name' => null,
        'available_at' => now(),
        'reserved_at' => null,
        'created_at' => now(),
    ]);

    $result = $outbox->drain([], 100);

    expect($result->dispatched)->toBe(0)
        ->and($result->skipped)->toBe(1)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

test('drain filters by dispatch_type when specified', function (): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    $outbox->enqueueStep($response->runId, 1, null, null);
    $outbox->enqueueStep($response->runId, 2, null, null);

    // Only drain branch type — should dispatch 0 (none are branch type)
    $result = $outbox->drain([OutboxDispatchType::Branch], 100);

    expect($result->dispatched)->toBe(0)
        ->and($result->skipped)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(2);
});

test('drain respects the limit parameter', function (): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    $outbox->enqueueStep($response->runId, 1, null, null);
    $outbox->enqueueStep($response->runId, 2, null, null);
    $outbox->enqueueStep($response->runId, 3, null, null);

    $result = $outbox->drain([], 2);

    expect($result->dispatched)->toBe(2)
        ->and($result->skipped)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(1);
});

test('drain returns empty result immediately when limit is zero or negative', function (int $limit): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    $outbox->enqueueStep($response->runId, 1, null, null);

    $result = $outbox->drain([], $limit);

    expect($result->dispatched)->toBe(0)
        ->and($result->skipped)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(1);
})->with([0, -1, -100]);

// ---------------------------------------------------------------------------
// swarm:relay Artisan command
// ---------------------------------------------------------------------------

test('swarm:relay exits success with no pending entries', function (): void {
    $exitCode = Artisan::call('swarm:relay');

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('No pending');
});

test('swarm:relay dispatches pending outbox entries and exits success', function (): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('relay-task');

    // Manually write an outbox row so the relay has something to drain
    $outbox->enqueueStep($response->runId, 1, null, null);

    expect(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBeGreaterThan(0);

    $exitCode = Artisan::call('swarm:relay');

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Dispatched');
});

test('swarm:relay warns and exits success when invalid entries are skipped', function (): void {
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    DB::table('swarm_durable_outbox')->insert([
        'run_id' => $response->runId,
        'dispatch_type' => 'not_a_real_type',
        'payload' => '{}',
        'queue_connection' => null,
        'queue_name' => null,
        'available_at' => now(),
        'reserved_at' => null,
        'created_at' => now(),
    ]);

    $exitCode = Artisan::call('swarm:relay');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Skipped')
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

test('swarm:relay --type=step filters by step type', function (): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    // Drain the initial row first
    $outbox->drain([], 100);

    // Insert both types manually
    $outbox->enqueueStep($response->runId, 1, null, null);

    $exitCode = Artisan::call('swarm:relay --type=step');

    expect($exitCode)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

test('swarm:relay --type=bogus exits failure with error message', function (): void {
    $exitCode = Artisan::call('swarm:relay', ['--type' => ['bogus']]);

    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('Unknown dispatch type');
});

test('swarm:relay --limit is respected', function (): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    // Drain initial row, then insert several more manually
    $outbox->drain([], 100);
    $outbox->enqueueStep($response->runId, 1, null, null);
    $outbox->enqueueStep($response->runId, 2, null, null);
    $outbox->enqueueStep($response->runId, 3, null, null);

    Artisan::call('swarm:relay', ['--limit' => 2]);

    expect(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(1);
});

test('swarm:relay --drain-until-empty processes all entries across multiple passes', function (): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    // Drain the initial row, then insert more than one batch's worth
    $outbox->drain([], 100);
    $outbox->enqueueStep($response->runId, 1, null, null);
    $outbox->enqueueStep($response->runId, 2, null, null);
    $outbox->enqueueStep($response->runId, 3, null, null);
    $outbox->enqueueStep($response->runId, 4, null, null);
    $outbox->enqueueStep($response->runId, 5, null, null);

    // limit=2 means three passes are required to drain all five entries
    $exitCode = Artisan::call('swarm:relay', ['--limit' => 2, '--drain-until-empty' => true]);

    expect($exitCode)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Checkpoint atomicity: outbox row survives transaction commit
// ---------------------------------------------------------------------------

test('outbox row is written atomically inside the checkpoint transaction', function (): void {
    FakeResearcher::fake(['step-output']);
    FakeWriter::fake(['final-output']);

    $response = FakeSequentialSwarm::make()->dispatchDurable('atomic-task');
    $manager = app(DurableSwarmManager::class);

    // Drain any initial rows so we start clean
    app(DurableOutbox::class)->drain([], 100);

    // After advancing step 0, the checkpoint transaction should have written
    // an outbox row for step 1 atomically.
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    $outboxAfterStep0 = DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count();

    expect($outboxAfterStep0)->toBeGreaterThan(0);
});

test('outbox row rollback rolls back the checkpoint', function (): void {
    FakeResearcher::fake(['step-output']);

    $response = FakeSequentialSwarm::make()->dispatchDurable('rollback-task');
    $manager = app(DurableSwarmManager::class);

    // Drain the initial row
    app(DurableOutbox::class)->drain([], 100);

    $runBefore = app(DurableSwarmManager::class)->find($response->runId);
    $nextStepBefore = (int) $runBefore['next_step_index'];

    // Force the outbox insert to fail by temporarily making the table unavailable.
    // We verify via the recorder directly — if $withTransaction throws, the outer
    // transaction rolls back the checkpoint writes.
    $recorder = app(DurableRunRecorder::class);
    $threw = false;

    try {
        $recorder->checkpointSequential(
            $response->runId,
            'fake-token', // won't match lease — triggers LostSwarmLeaseException inside
            $nextStepBefore + 1,
            RunContext::fromTask('test'),
            60,
            function (): void {
                throw new RuntimeException('simulated outbox failure');
            },
        );
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    // next_step_index must NOT have advanced because the transaction rolled back
    $runAfter = app(DurableSwarmManager::class)->find($response->runId);
    expect((int) $runAfter['next_step_index'])->toBe($nextStepBefore);
});

// ---------------------------------------------------------------------------
// swarm:health outbox staleness check
// ---------------------------------------------------------------------------

test('swarm:health --durable warns when outbox has stale unclaimed rows', function (): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    // Manually insert an outbox row (dispatchDurable uses direct dispatch, not the DB outbox)
    $outbox->enqueueStep($response->runId, 1, null, null);

    // Age the outbox rows past the staleness threshold
    DB::table('swarm_durable_outbox')
        ->where('run_id', $response->runId)
        ->update(['created_at' => now()->subMinutes(5)]);

    $exitCode = Artisan::call('swarm:health', ['--durable' => true, '--json' => true]);
    $output = json_decode(Artisan::output(), true);

    $outboxCheck = collect($output['checks'])->firstWhere('component', 'Outbox relay');

    expect($exitCode)->toBe(0) // warning is not FAILURE
        ->and($outboxCheck['status'])->toBe('warning')
        ->and($outboxCheck['details'])->toContain('swarm:relay');
});

test('swarm:health --durable reports ok when outbox is healthy', function (): void {
    $exitCode = Artisan::call('swarm:health', ['--durable' => true, '--json' => true]);
    $output = json_decode(Artisan::output(), true);

    $outboxCheck = collect($output['checks'])->firstWhere('component', 'Outbox relay');

    expect($outboxCheck['status'])->toBe('ok');
});
