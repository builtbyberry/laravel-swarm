<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryPurged;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Feature coverage for `swarm:memory:purge` retention enforcement.
 *
 * Exercises per-scope retention, --dry-run preview, --scope filter, snapshot
 * cascade behavior, and `MemoryPurged` event dispatch.
 */
beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
});

function seedRunHistory(string $runId): void
{
    $now = Carbon::now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'completed',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * Insert a swarm_memories row directly so we control `created_at`. The
 * DatabaseMemoryStore stamps `now()` on persist, which won't let us simulate
 * aged rows.
 */
function seedMemoryRow(MemoryScope $scope, string $scopeId, string $key, Carbon $createdAt, ?string $runId = null): int
{
    if ($scope === MemoryScope::Run) {
        seedRunHistory($scopeId);
        $runId ??= $scopeId;
    }

    return (int) DB::table('swarm_memories')->insertGetId([
        'scope' => $scope->value,
        'scope_id' => $scopeId,
        'run_id' => $scope === MemoryScope::Run ? $runId : null,
        'key' => $key,
        'value' => json_encode('payload'),
        'metadata' => json_encode([]),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function seedSnapshotRow(string $runId, int $stepIndex, Carbon $createdAt): int
{
    return (int) DB::table('swarm_memory_snapshots')->insertGetId([
        'run_id' => $runId,
        'step_index' => $stepIndex,
        'payload' => json_encode([]),
        'tool_calls' => json_encode([]),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

test('swarm:memory:purge is a no-op when the persistence driver is not database', function (): void {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.memory.retention.days.agent', 1);

    $exit = Artisan::call('swarm:memory:purge');

    expect($exit)->toBe(0);
});

test('swarm:memory:purge deletes rows older than the per-scope retention window', function (): void {
    config()->set('swarm.memory.retention.days', [
        'run' => 7,
        'conversation' => 30,
        'agent' => null,
        'swarm' => null,
    ]);

    $oldRun = seedMemoryRow(MemoryScope::Run, 'run-old', 'k', Carbon::now('UTC')->subDays(10));
    $freshRun = seedMemoryRow(MemoryScope::Run, 'run-fresh', 'k', Carbon::now('UTC')->subDays(2));
    $oldConv = seedMemoryRow(MemoryScope::Conversation, 'conv-old', 'k', Carbon::now('UTC')->subDays(60));
    $freshConv = seedMemoryRow(MemoryScope::Conversation, 'conv-fresh', 'k', Carbon::now('UTC')->subDays(5));
    $oldAgent = seedMemoryRow(MemoryScope::Agent, 'AgentClass', 'k', Carbon::now('UTC')->subDays(365));

    Artisan::call('swarm:memory:purge');

    expect(DB::table('swarm_memories')->where('id', $oldRun)->exists())->toBeFalse();
    expect(DB::table('swarm_memories')->where('id', $freshRun)->exists())->toBeTrue();
    expect(DB::table('swarm_memories')->where('id', $oldConv)->exists())->toBeFalse();
    expect(DB::table('swarm_memories')->where('id', $freshConv)->exists())->toBeTrue();
    // No retention configured for the Agent scope — row survives.
    expect(DB::table('swarm_memories')->where('id', $oldAgent)->exists())->toBeTrue();
});

test('swarm:memory:purge compares created_at against the cutoff instant, not the calendar day', function (): void {
    // Regression guard for the cutoff binding: the cutoff must reach the query
    // as a native datetime, not a pre-formatted ISO-8601 string. A row one hour
    // *inside* the window must survive; a row one hour *outside* must be purged.
    // With a string cutoff like "2026-05-24T10:00:00+00:00" this fails — on
    // SQLite every same-day row sorts lexicographically before the "T", so the
    // inside-window row is wrongly deleted; on MySQL the literal is an invalid
    // datetime. Existing tests only used rows days from the boundary and never
    // caught it.
    config()->set('swarm.memory.retention.days.run', 7);

    $cutoff = Carbon::now('UTC')->subDays(7);

    $insideWindow = seedMemoryRow(MemoryScope::Run, 'run-inside', 'k', $cutoff->copy()->addHour());
    $outsideWindow = seedMemoryRow(MemoryScope::Run, 'run-outside', 'k', $cutoff->copy()->subHour());

    Artisan::call('swarm:memory:purge');

    expect(DB::table('swarm_memories')->where('id', $insideWindow)->exists())->toBeTrue();
    expect(DB::table('swarm_memories')->where('id', $outsideWindow)->exists())->toBeFalse();
});

test('swarm:memory:purge --dry-run reports counts without deleting any rows', function (): void {
    config()->set('swarm.memory.retention.days.run', 7);

    seedMemoryRow(MemoryScope::Run, 'run-old-a', 'k', Carbon::now('UTC')->subDays(10));
    seedMemoryRow(MemoryScope::Run, 'run-old-b', 'k', Carbon::now('UTC')->subDays(20));

    Event::fake([MemoryPurged::class]);

    Artisan::call('swarm:memory:purge', ['--dry-run' => true]);

    expect(DB::table('swarm_memories')->count())->toBe(2);

    Event::assertDispatched(MemoryPurged::class, function (MemoryPurged $event): bool {
        return $event->criteria['dry_run'] === true
            && ($event->counts['run'] ?? 0) === 2;
    });
});

test('swarm:memory:purge --scope limits the purge to the requested scope', function (): void {
    config()->set('swarm.memory.retention.days', [
        'run' => 7,
        'conversation' => 7,
        'agent' => null,
        'swarm' => null,
    ]);

    $oldRun = seedMemoryRow(MemoryScope::Run, 'run-old', 'k', Carbon::now('UTC')->subDays(30));
    $oldConv = seedMemoryRow(MemoryScope::Conversation, 'conv-old', 'k', Carbon::now('UTC')->subDays(30));

    Artisan::call('swarm:memory:purge', ['--scope' => 'run']);

    // Only Run-scoped row was eligible.
    expect(DB::table('swarm_memories')->where('id', $oldRun)->exists())->toBeFalse();
    expect(DB::table('swarm_memories')->where('id', $oldConv)->exists())->toBeTrue();
});

test('swarm:memory:purge rejects unknown --scope values', function (): void {
    config()->set('swarm.memory.retention.days.run', 1);

    $exit = Artisan::call('swarm:memory:purge', ['--scope' => 'bogus']);

    expect($exit)->toBe(1);
});

test('swarm:memory:purge dispatches MemoryPurged with per-scope counts and criteria', function (): void {
    config()->set('swarm.memory.retention.days', [
        'run' => 5,
        'conversation' => null,
        'agent' => null,
        'swarm' => null,
    ]);

    seedMemoryRow(MemoryScope::Run, 'run-old-1', 'k', Carbon::now('UTC')->subDays(10));
    seedMemoryRow(MemoryScope::Run, 'run-old-2', 'k', Carbon::now('UTC')->subDays(12));

    Event::fake([MemoryPurged::class]);

    Artisan::call('swarm:memory:purge');

    Event::assertDispatched(MemoryPurged::class, function (MemoryPurged $event): bool {
        return $event->counts['run'] === 2
            && $event->criteria['retention_days']['run'] === 5
            && $event->criteria['dry_run'] === false
            && $event->criteria['prevent_prune'] === false
            && $event->criteria['scope_filter'] === null
            && $event->criteria['prune_snapshots'] === true
            && isset($event->criteria['cutoffs']['run']);
    });
});

test('swarm:memory:purge cascades snapshot rows for Run-scoped purges by default', function (): void {
    config()->set('swarm.memory.retention.days.run', 7);

    seedMemoryRow(MemoryScope::Run, 'run-old', 'k', Carbon::now('UTC')->subDays(30));
    seedSnapshotRow('run-old', 0, Carbon::now('UTC')->subDays(30));
    seedSnapshotRow('run-old', 1, Carbon::now('UTC')->subDays(30));

    seedMemoryRow(MemoryScope::Run, 'run-fresh', 'k', Carbon::now('UTC')->subDays(1));
    seedSnapshotRow('run-fresh', 0, Carbon::now('UTC')->subDays(1));

    Event::fake([MemoryPurged::class]);

    Artisan::call('swarm:memory:purge');

    expect(DB::table('swarm_memory_snapshots')->where('run_id', 'run-old')->count())->toBe(0);
    expect(DB::table('swarm_memory_snapshots')->where('run_id', 'run-fresh')->count())->toBe(1);

    Event::assertDispatched(MemoryPurged::class, function (MemoryPurged $event): bool {
        return $event->counts['run'] === 1
            && ($event->counts['snapshots'] ?? null) === 2;
    });
});

test('swarm:memory:purge cascades a run\'s snapshots when any of its Run-scoped memory rows ages out', function (): void {
    // A long-running/durable run can hold multiple Run-scoped memory rows that
    // straddle the cutoff. Documented semantics: if ANY Run-scoped row for a
    // run_id is older than the cutoff, that run's snapshots cascade — even
    // though a fresher Run-scoped row for the same run survives. This locks in
    // the behavior so a future change to the cascade keying is a conscious one.
    config()->set('swarm.memory.retention.days.run', 7);

    seedRunHistory('run-straddle');

    $aged = (int) DB::table('swarm_memories')->insertGetId([
        'scope' => MemoryScope::Run->value,
        'scope_id' => 'run-straddle',
        'run_id' => 'run-straddle',
        'key' => 'aged-key',
        'value' => json_encode('payload'),
        'metadata' => json_encode([]),
        'created_at' => Carbon::now('UTC')->subDays(30),
        'updated_at' => Carbon::now('UTC')->subDays(30),
    ]);

    $fresh = (int) DB::table('swarm_memories')->insertGetId([
        'scope' => MemoryScope::Run->value,
        'scope_id' => 'run-straddle',
        'run_id' => 'run-straddle',
        'key' => 'fresh-key',
        'value' => json_encode('payload'),
        'metadata' => json_encode([]),
        'created_at' => Carbon::now('UTC')->subDays(1),
        'updated_at' => Carbon::now('UTC')->subDays(1),
    ]);

    seedSnapshotRow('run-straddle', 0, Carbon::now('UTC')->subDays(30));
    seedSnapshotRow('run-straddle', 1, Carbon::now('UTC')->subDays(1));

    Artisan::call('swarm:memory:purge');

    // The aged Run-scoped row is purged; the fresh one survives.
    expect(DB::table('swarm_memories')->where('id', $aged)->exists())->toBeFalse();
    expect(DB::table('swarm_memories')->where('id', $fresh)->exists())->toBeTrue();

    // Both snapshots cascade because the run_id matched an aged Run-scoped row,
    // including the 1-day-old snapshot whose memory sibling survived.
    expect(DB::table('swarm_memory_snapshots')->where('run_id', 'run-straddle')->count())->toBe(0);
});

test('swarm:memory:purge --keep-snapshots leaves swarm_memory_snapshots intact', function (): void {
    config()->set('swarm.memory.retention.days.run', 7);

    seedMemoryRow(MemoryScope::Run, 'run-old', 'k', Carbon::now('UTC')->subDays(30));
    $snapshotId = seedSnapshotRow('run-old', 0, Carbon::now('UTC')->subDays(30));

    Event::fake([MemoryPurged::class]);

    Artisan::call('swarm:memory:purge', ['--keep-snapshots' => true]);

    // Note: the memory row is gone, but because the snapshot FKs to
    // swarm_run_histories.run_id (not swarm_memories.run_id), the snapshot row
    // survives so the operator opt-out is meaningful.
    expect(DB::table('swarm_memories')->where('scope_id', 'run-old')->exists())->toBeFalse();
    expect(DB::table('swarm_memory_snapshots')->where('id', $snapshotId)->exists())->toBeTrue();

    Event::assertDispatched(MemoryPurged::class, function (MemoryPurged $event): bool {
        return $event->criteria['prune_snapshots'] === false
            && ! array_key_exists('snapshots', $event->counts);
    });
});

test('swarm:memory:purge respects swarm.retention.prevent_prune and still emits the event', function (): void {
    config()->set('swarm.memory.retention.days.run', 1);
    config()->set('swarm.retention.prevent_prune', true);

    seedMemoryRow(MemoryScope::Run, 'run-old', 'k', Carbon::now('UTC')->subDays(30));

    Event::fake([MemoryPurged::class]);

    Artisan::call('swarm:memory:purge');

    // Destructive delete suppressed.
    expect(DB::table('swarm_memories')->count())->toBe(1);

    Event::assertDispatched(MemoryPurged::class, function (MemoryPurged $event): bool {
        return ($event->counts['run'] ?? null) === 0
            && $event->criteria['dry_run'] === false
            && $event->criteria['prevent_prune'] === true;
    });
});

test('swarm:memory:purge is a no-op (warning) when no scopes have retention configured', function (): void {
    config()->set('swarm.memory.retention.days', [
        'run' => null,
        'conversation' => null,
        'agent' => null,
        'swarm' => null,
    ]);

    seedMemoryRow(MemoryScope::Run, 'run-1', 'k', Carbon::now('UTC')->subDays(10));

    Event::fake([MemoryPurged::class]);

    Artisan::call('swarm:memory:purge');

    expect(DB::table('swarm_memories')->count())->toBe(1);

    Event::assertDispatched(MemoryPurged::class);
});

test('the scheduler example in docs/advanced-setup.md references swarm:memory:purge', function (): void {
    $docs = file_get_contents(__DIR__.'/../../../docs/advanced-setup.md');

    expect($docs)->toContain("Schedule::command('swarm:memory:purge')");
});

test('swarm:memory:purge is registered with the package', function (): void {
    /** @var Kernel $kernel */
    $kernel = $this->app->make(Kernel::class);

    expect(array_key_exists('swarm:memory:purge', $kernel->all()))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Audit category — `command.memory.purge` is the load-bearing compliance
// artifact for retention enforcement: the positive record of *what was removed
// (or would be), under what criteria, and when*. The tests above assert the
// in-process `MemoryPurged` event; these assert the audit-sink category the
// dispatcher enriches and forwards. The prevent_prune path is the one most at
// risk of regression — a disabled purge must still leave an audit trail showing
// the scheduled run happened and was skipped. Mirrors the `command.memory.dump`
// coverage via the shared RecordingSwarmAuditSink.
// ---------------------------------------------------------------------------

function purgeBindRecordingAuditSink(): RecordingSwarmAuditSink
{
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    return $sink;
}

test('emits the command.memory.purge audit category with per-scope counts and criteria on a real prune', function (): void {
    config()->set('swarm.memory.retention.days', [
        'run' => 5,
        'conversation' => null,
        'agent' => null,
        'swarm' => null,
    ]);

    seedMemoryRow(MemoryScope::Run, 'run-old-1', 'k', Carbon::now('UTC')->subDays(10));
    seedMemoryRow(MemoryScope::Run, 'run-old-2', 'k', Carbon::now('UTC')->subDays(12));

    $sink = purgeBindRecordingAuditSink();

    $exit = Artisan::call('swarm:memory:purge');

    expect($exit)->toBe(0)
        ->and($sink->hasCategory('command.memory.purge'))->toBeTrue()
        ->and($sink->recordsForCategory('command.memory.purge'))->toHaveCount(1);

    $record = $sink->recordsForCategory('command.memory.purge')[0];

    expect($record['category'])->toBe('command.memory.purge')
        ->and($record['status'])->toBe('purged')
        ->and($record['dry_run'])->toBeFalse()
        ->and($record['prevent_prune'])->toBeFalse()
        // Per-scope counts — what was removed.
        ->and($record['counts']['run'])->toBe(2)
        // Criteria — the parameters the operator ran with.
        ->and($record['criteria']['retention_days']['run'])->toBe(5)
        ->and($record['criteria']['scope_filter'])->toBeNull()
        ->and($record['criteria']['prune_snapshots'])->toBeTrue()
        ->and($record['criteria']['cutoffs'])->toHaveKey('run')
        // Actor identity — the "who" — is carried in reserved metadata.
        ->and($record['metadata_keys'])->toContain('actor')
        ->and($record['metadata'])->toHaveKey('actor');
});

test('emits the command.memory.purge audit category with status=skipped on the prevent_prune path', function (): void {
    config()->set('swarm.memory.retention.days.run', 1);
    config()->set('swarm.retention.prevent_prune', true);

    seedMemoryRow(MemoryScope::Run, 'run-old', 'k', Carbon::now('UTC')->subDays(30));

    $sink = purgeBindRecordingAuditSink();

    $exit = Artisan::call('swarm:memory:purge');

    // Destructive delete suppressed, but the audit trail must still record the run.
    expect($exit)->toBe(0)
        ->and(DB::table('swarm_memories')->count())->toBe(1)
        ->and($sink->hasCategory('command.memory.purge'))->toBeTrue();

    $record = $sink->recordsForCategory('command.memory.purge')[0];

    expect($record['status'])->toBe('skipped')
        ->and($record['prevent_prune'])->toBeTrue()
        ->and($record['dry_run'])->toBeFalse()
        // Counts are zeroed because nothing was deleted.
        ->and($record['counts']['run'])->toBe(0)
        ->and($record['criteria']['prevent_prune'])->toBeTrue();
});

test('emits the command.memory.purge audit category with status=dry_run on a --dry-run preview', function (): void {
    config()->set('swarm.memory.retention.days.run', 7);

    seedMemoryRow(MemoryScope::Run, 'run-old-a', 'k', Carbon::now('UTC')->subDays(10));
    seedMemoryRow(MemoryScope::Run, 'run-old-b', 'k', Carbon::now('UTC')->subDays(20));

    $sink = purgeBindRecordingAuditSink();

    $exit = Artisan::call('swarm:memory:purge', ['--dry-run' => true]);

    expect($exit)->toBe(0)
        // Nothing deleted on a dry run, but the access is still recorded.
        ->and(DB::table('swarm_memories')->count())->toBe(2);

    $record = $sink->recordsForCategory('command.memory.purge')[0];

    expect($record['status'])->toBe('dry_run')
        ->and($record['dry_run'])->toBeTrue()
        ->and($record['counts']['run'])->toBe(2);
});

test('does not emit the command.memory.purge audit category when the persistence driver is not database', function (): void {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.memory.retention.days.run', 1);

    $sink = purgeBindRecordingAuditSink();

    $exit = Artisan::call('swarm:memory:purge');

    // The command no-ops before any retention work, so there is nothing to audit.
    expect($exit)->toBe(0)
        ->and($sink->hasCategory('command.memory.purge'))->toBeFalse();
});
