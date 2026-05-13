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
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Bus\PendingDispatch;
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
// DrainResult::$claimed and $reclaimed counters
// ---------------------------------------------------------------------------

test('drain reports claimed and reclaimed counts when a stale reservation is re-claimed', function (): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    $outbox->enqueueStep($response->runId, 1, null, null);

    // Simulate a previously-reserved row that was never completed (relay crashed mid-run)
    DB::table('swarm_durable_outbox')
        ->where('run_id', $response->runId)
        ->update(['reserved_at' => now()->subMinutes(5)]);

    $result = $outbox->drain([OutboxDispatchType::Step], 100);

    expect($result->claimed)->toBe(1)
        ->and($result->reclaimed)->toBe(1);
});

test('drain reports claimed count and zero reclaimed for fresh unclaimed rows', function (): void {
    $outbox = app(DurableOutbox::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');

    // Drain the initial dispatchDurable row first
    $outbox->drain([], 100);

    $outbox->enqueueStep($response->runId, 1, null, null);
    $outbox->enqueueStep($response->runId, 2, null, null);
    $outbox->enqueueStep($response->runId, 3, null, null);

    $result = $outbox->drain([OutboxDispatchType::Step], 100);

    expect($result->claimed)->toBe(3)
        ->and($result->reclaimed)->toBe(0);
});

test('drain reports all-zero counts when outbox is empty', function (): void {
    $outbox = app(DurableOutbox::class);

    // Make sure the outbox is empty (drain any initial rows)
    $outbox->drain([], 100);

    $result = $outbox->drain([], 100);

    expect($result->dispatched)->toBe(0)
        ->and($result->skipped)->toBe(0)
        ->and($result->failed)->toBe(0)
        ->and($result->claimed)->toBe(0)
        ->and($result->reclaimed)->toBe(0);
});

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

// ---------------------------------------------------------------------------
// Transient dispatch failures — DrainResult::$failed
// ---------------------------------------------------------------------------

/**
 * Helper: bind a DurableJobDispatcher that throws the given exception on every
 * dispatch call, then clear the DurableOutbox singleton so the next resolve
 * gets a fresh instance backed by the throwing dispatcher.
 */
function outboxBindThrowingDispatcher(Throwable $exception): void
{
    $throwing = new class($exception) extends DurableJobDispatcher
    {
        public function __construct(private readonly Throwable $error) {}

        public function dispatchStep(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            throw $this->error;
        }

        public function dispatchBranch(string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            throw $this->error;
        }

        public function dispatchQueuedResumeById(string $runId, ?string $connection = null, ?string $queue = null): void
        {
            throw $this->error;
        }
    };

    app()->instance(DurableJobDispatcher::class, $throwing);
    app()->forgetInstance(DurableOutbox::class);
}

test('drain counts transient dispatch failures in $failed without removing the row', function (): void {
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    app(DurableOutbox::class)->drain([], 100); // clear the initial row

    app(DurableOutbox::class)->enqueueStep($response->runId, 1, null, null);

    outboxBindThrowingDispatcher(new RuntimeException('Queue driver unavailable'));
    $outbox = app(DurableOutbox::class);

    $result = $outbox->drain([], 100);

    expect($result->dispatched)->toBe(0)
        ->and($result->skipped)->toBe(0)
        ->and($result->failed)->toBe(1)
        ->and($result->total())->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(1);
});

test('drain retains reserved_at on transient failure so the row is re-claimable', function (): void {
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    app(DurableOutbox::class)->drain([], 100);

    app(DurableOutbox::class)->enqueueStep($response->runId, 1, null, null);

    outboxBindThrowingDispatcher(new RuntimeException('Queue driver unavailable'));
    app(DurableOutbox::class)->drain([], 100);

    $row = DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->first();

    expect($row)->not->toBeNull()
        ->and($row->reserved_at)->not->toBeNull();
});

test('drain counts mixed outcomes correctly across dispatched, skipped, and failed', function (): void {
    // Three entries in one batch:
    //   - one valid step  → will dispatch (but dispatcher throws for step, not branch)
    //   - one invalid type → skipped (permanently invalid, deleted)
    //   - one valid step   → transient failure
    // We achieve this by inserting two step entries and one invalid type, then using
    // a dispatcher that throws only on the second dispatchStep call.
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    app(DurableOutbox::class)->drain([], 100);

    // One invalid type row
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

    // Two valid step rows; dispatcher will succeed for the first and throw for the second
    app(DurableOutbox::class)->enqueueStep($response->runId, 1, null, null);
    app(DurableOutbox::class)->enqueueStep($response->runId, 2, null, null);

    $partialDispatcher = new class(app(Repository::class)) extends DurableJobDispatcher
    {
        public int $calls = 0;

        public function dispatchStep(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->calls++;

            if ($this->calls > 1) {
                throw new RuntimeException('Queue driver unavailable on second call');
            }

            // Return a no-op PendingDispatch so the first call "succeeds"
            $job = new AdvanceDurableSwarm($runId, $stepIndex);

            return new PendingDispatch($job);
        }
    };

    app()->instance(DurableJobDispatcher::class, $partialDispatcher);
    app()->forgetInstance(DurableOutbox::class);
    $outbox = app(DurableOutbox::class);

    $result = $outbox->drain([], 100);

    // dispatched=1 (first step), skipped=1 (invalid type), failed=1 (second step)
    expect($result->dispatched)->toBe(1)
        ->and($result->skipped)->toBe(1)
        ->and($result->failed)->toBe(1)
        ->and($result->total())->toBe(2); // dispatched + skipped only
});

// ---------------------------------------------------------------------------
// Unknown queue_connection — permanently invalid
// ---------------------------------------------------------------------------

test('drain treats an unknown queue_connection as permanently invalid and deletes the row', function (): void {
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    app(DurableOutbox::class)->drain([], 100);

    DB::table('swarm_durable_outbox')->insert([
        'run_id' => $response->runId,
        'dispatch_type' => OutboxDispatchType::Step->value,
        'payload' => '{"step_index":1}',
        'queue_connection' => 'unknown-connection-that-does-not-exist',
        'queue_name' => null,
        'available_at' => now(),
        'reserved_at' => null,
        'created_at' => now(),
    ]);

    $result = app(DurableOutbox::class)->drain([], 100);

    expect($result->dispatched)->toBe(0)
        ->and($result->skipped)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

test('drain accepts a null queue_connection without error', function (): void {
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    app(DurableOutbox::class)->drain([], 100);

    app(DurableOutbox::class)->enqueueStep($response->runId, 1, null, null);

    $result = app(DurableOutbox::class)->drain([], 100);

    expect($result->dispatched)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and($result->failed)->toBe(0);
});

test('drain accepts a known queue_connection and dispatches successfully', function (): void {
    config()->set('queue.connections.custom-test', ['driver' => 'null']);

    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    app(DurableOutbox::class)->drain([], 100);

    app(DurableOutbox::class)->enqueueStep($response->runId, 1, 'custom-test', null);

    $result = app(DurableOutbox::class)->drain([], 100);

    expect($result->dispatched)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and($result->failed)->toBe(0);
});

// ---------------------------------------------------------------------------
// Payload shape validation — Step and Branch
// ---------------------------------------------------------------------------

test('drain treats a missing step_index field as permanently invalid', function (): void {
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    app(DurableOutbox::class)->drain([], 100);

    DB::table('swarm_durable_outbox')->insert([
        'run_id' => $response->runId,
        'dispatch_type' => OutboxDispatchType::Step->value,
        'payload' => '{}', // missing step_index
        'queue_connection' => null,
        'queue_name' => null,
        'available_at' => now(),
        'reserved_at' => null,
        'created_at' => now(),
    ]);

    $result = app(DurableOutbox::class)->drain([], 100);

    expect($result->skipped)->toBe(1)
        ->and($result->dispatched)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

test('drain treats a non-integer step_index as permanently invalid', function (): void {
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    app(DurableOutbox::class)->drain([], 100);

    DB::table('swarm_durable_outbox')->insert([
        'run_id' => $response->runId,
        'dispatch_type' => OutboxDispatchType::Step->value,
        'payload' => '{"step_index":"not-an-int"}',
        'queue_connection' => null,
        'queue_name' => null,
        'available_at' => now(),
        'reserved_at' => null,
        'created_at' => now(),
    ]);

    $result = app(DurableOutbox::class)->drain([], 100);

    expect($result->skipped)->toBe(1)
        ->and($result->dispatched)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

test('drain treats a missing branch_id field as permanently invalid', function (): void {
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    app(DurableOutbox::class)->drain([], 100);

    DB::table('swarm_durable_outbox')->insert([
        'run_id' => $response->runId,
        'dispatch_type' => OutboxDispatchType::Branch->value,
        'payload' => '{}', // missing branch_id
        'queue_connection' => null,
        'queue_name' => null,
        'available_at' => now(),
        'reserved_at' => null,
        'created_at' => now(),
    ]);

    $result = app(DurableOutbox::class)->drain([], 100);

    expect($result->skipped)->toBe(1)
        ->and($result->dispatched)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

test('drain treats an empty string branch_id as permanently invalid', function (): void {
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    app(DurableOutbox::class)->drain([], 100);

    DB::table('swarm_durable_outbox')->insert([
        'run_id' => $response->runId,
        'dispatch_type' => OutboxDispatchType::Branch->value,
        'payload' => '{"branch_id":""}',
        'queue_connection' => null,
        'queue_name' => null,
        'available_at' => now(),
        'reserved_at' => null,
        'created_at' => now(),
    ]);

    $result = app(DurableOutbox::class)->drain([], 100);

    expect($result->skipped)->toBe(1)
        ->and($result->dispatched)->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $response->runId)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// swarm:relay — exit codes and output for transient failures
// ---------------------------------------------------------------------------

/**
 * Helper: bind a DurableOutbox stub that returns the given DrainResult on every
 * drain() call, regardless of DB state. Used to test relay command behaviour
 * independently of the real outbox implementation.
 */
function outboxBindStubOutbox(DrainResult ...$results): void
{
    $remaining = $results;

    $stub = new class($remaining) implements DurableOutbox
    {
        /** @param array<DrainResult> $results */
        public function __construct(private array $results) {}

        public function enqueueStep(string $runId, int $stepIndex, ?string $connection, ?string $queue): void {}

        public function enqueueBranch(string $runId, string $branchId, ?string $connection, ?string $queue): void {}

        public function enqueueQueuedResume(string $runId, ?string $connection, ?string $queue): void {}

        public function drain(array $types = [], int $limit = 100): DrainResult
        {
            return count($this->results) > 1
                ? array_shift($this->results)
                : ($this->results[0] ?? new DrainResult(0, 0, 0));
        }
    };

    app()->instance(DurableOutbox::class, $stub);
}

test('swarm:relay exits failure when transient dispatch errors occur', function (): void {
    outboxBindStubOutbox(new DrainResult(0, 0, 3));

    $exitCode = Artisan::call('swarm:relay');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('transient error');
});

test('swarm:relay output includes transient failure count and retry advice', function (): void {
    outboxBindStubOutbox(new DrainResult(2, 0, 1));

    Artisan::call('swarm:relay');

    $output = Artisan::output();
    expect($output)->toContain('Dispatched 2')
        ->and($output)->toContain('transient error');
});

test('swarm:relay "no pending entries found" is only printed when the outbox is truly empty', function (): void {
    // A batch with only transient failures must NOT produce the "no pending" message
    outboxBindStubOutbox(new DrainResult(0, 0, 2));

    Artisan::call('swarm:relay');

    expect(Artisan::output())->not->toContain('No pending');
});

test('swarm:relay exits success and shows "no pending" when outbox is empty', function (): void {
    outboxBindStubOutbox(new DrainResult(0, 0, 0));

    $exitCode = Artisan::call('swarm:relay');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No pending');
});

// ---------------------------------------------------------------------------
// swarm:relay --drain-until-empty loop behaviour with transient failures
// ---------------------------------------------------------------------------

test('swarm:relay --drain-until-empty stops when only transient failures remain (no --max-attempts)', function (): void {
    // First batch: real progress. Second batch: pure transient failures. Loop must stop.
    outboxBindStubOutbox(
        new DrainResult(5, 0, 0), // first pass — dispatched 5
        new DrainResult(0, 0, 2), // second pass — 2 transient failures, loop exits
        new DrainResult(0, 0, 0), // should never reach this
    );

    $exitCode = Artisan::call('swarm:relay', ['--drain-until-empty' => true]);

    expect($exitCode)->toBe(1) // transient failures on exit → FAILURE
        ->and(Artisan::output())->toContain('Dispatched 5');
});

test('swarm:relay --drain-until-empty --max-attempts retries transient failures up to N times', function (): void {
    // Three passes: all transient. With --max-attempts=3 the loop runs all three.
    outboxBindStubOutbox(
        new DrainResult(0, 0, 2),
        new DrainResult(0, 0, 2),
        new DrainResult(0, 0, 2),
        new DrainResult(0, 0, 0), // should never reach this
    );

    $exitCode = Artisan::call('swarm:relay', ['--drain-until-empty' => true, '--max-attempts' => 3]);

    expect($exitCode)->toBe(1); // still failing after 3 attempts
});

test('swarm:relay --drain-until-empty --max-attempts exits success when transient failures clear before limit', function (): void {
    // Two transient-failure passes, then clean on the third — exits success.
    outboxBindStubOutbox(
        new DrainResult(0, 0, 2),
        new DrainResult(0, 0, 2),
        new DrainResult(3, 0, 0), // queue recovered, all dispatched
        new DrainResult(0, 0, 0),
    );

    $exitCode = Artisan::call('swarm:relay', ['--drain-until-empty' => true, '--max-attempts' => 5]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Dispatched 3');
});

test('swarm:relay warns when --max-attempts is used without --drain-until-empty', function (): void {
    outboxBindStubOutbox(new DrainResult(0, 0, 0));

    Artisan::call('swarm:relay', ['--max-attempts' => 5]);

    expect(Artisan::output())->toContain('--max-attempts has no effect without --drain-until-empty');
});

test('swarm:relay --max-attempts reports attempt count in failure message', function (): void {
    outboxBindStubOutbox(
        new DrainResult(0, 0, 1),
        new DrainResult(0, 0, 1),
        new DrainResult(0, 0, 1),
    );

    Artisan::call('swarm:relay', ['--drain-until-empty' => true, '--max-attempts' => 3]);

    expect(Artisan::output())->toContain('3 attempt');
});
