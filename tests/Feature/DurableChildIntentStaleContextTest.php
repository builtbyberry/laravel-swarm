<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\DurableChildRun;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\StaleSnapshotContextStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * #650: recording a child swarm's dispatch intent merges the child into the parent
 * run's context and writes the row back. It reads that context with a locking read,
 * once the parent run is locked, so the context it writes back is the latest committed
 * one and not an older read that predates another writer's commit.
 *
 * SQLite cannot hold an older snapshot across another writer's commit, so these tests
 * are a deterministic stale-read simulation: StaleSnapshotContextStore keeps answering
 * an ordinary find() with the context as it was when the snapshot was taken. Which
 * engines actually lose the write, and when, is observed on MySQL and Postgres in
 * tests/ProcessConcurrency/DurableJoinOpenWaitTest.php.
 */
beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    // Bound before anything resolves the manager: its collaborators capture the store.
    app()->instance(ContextStore::class, app(StaleSnapshotContextStore::class));

    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

function childIntentStaleContextStore(): StaleSnapshotContextStore
{
    $store = app(ContextStore::class);

    expect($store)->toBeInstanceOf(StaleSnapshotContextStore::class);

    return $store;
}

/**
 * A parent run with two open waits, so it stays waiting whichever one closes.
 */
function childIntentStaleContextParent(): string
{
    $runId = FakeSequentialSwarm::make()->dispatchDurable('child-intent-stale-context-task')->runId;
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'approval');
    $manager->wait($runId, 'other');

    return $runId;
}

/**
 * Dispatch the child and return it with the parent's locking-read count before the
 * dispatch: the staged write may itself read with a lock, so only the dispatch's own
 * reads are compared.
 *
 * @return array{0: DurableChildRun, 1: int}
 */
function childIntentStaleContextDispatch(string $runId, ?string $dedupeKey = null): array
{
    $before = childIntentStaleContextStore()->lockingReads[$runId] ?? 0;
    $child = app(DurableSwarmManager::class)->dispatchChildSwarm($runId, FakeSequentialSwarm::class, 'child-task', $dedupeKey);

    return [$child, $before];
}

/**
 * The child is recorded on the parent: its dispatch entry in the context and history,
 * its open wait, its lineage row and its own durable run.
 *
 * @param  array<string, mixed>  $context
 */
function childIntentStaleContextAssertChildRecorded(string $runId, DurableChildRun $child, array $context, string $dispatchKey): void
{
    $history = app(RunHistoryStore::class)->find($runId);

    expect($context['metadata']['durable_dispatched_child_swarms'][$dispatchKey] ?? null)->toBeTrue(json_encode($context['metadata']))
        ->and($history['metadata']['durable_dispatched_child_swarms'][$dispatchKey] ?? null)->toBeTrue()
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', 'child:'.$child->childRunId)->value('status'))->toBe('waiting')
        ->and(DB::table('swarm_durable_child_runs')->where('parent_run_id', $runId)->where('child_run_id', $child->childRunId)->exists())->toBeTrue()
        ->and(DB::table('swarm_durable_runs')->where('run_id', $child->childRunId)->exists())->toBeTrue()
        ->and(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('waiting');
}

/**
 * @return array<string, mixed>
 */
function childIntentStaleContextCurrent(string $runId): array
{
    $store = childIntentStaleContextStore();
    $store->dropSnapshot($runId);

    return (array) $store->find($runId);
}

test('a child intent recorded from a stale read keeps the context a fan-out committed since, and records the child (#650)', function (): void {
    $runId = childIntentStaleContextParent();
    $store = childIntentStaleContextStore();
    $store->takeSnapshot($runId);

    // What a fan-out saves, committed through a separate plain store after the read.
    $fanOut = RunContext::fromPayload((array) (new DatabaseContextStore(DB::connection(), config(), app(SwarmPersistenceCipher::class)))->find($runId));
    $fanOut->mergeData(['fan_out_step' => 'fan-out-output'])
        ->mergeMetadata(['fan_out_marker' => 'saved-by-fan-out'])
        ->addArtifact(new SwarmArtifact(name: 'fan-out-artifact', content: 'fan-out-artifact-content'));
    $store->put($fanOut, 3600);

    expect($store->find($runId)['data'])->not->toHaveKey('fan_out_step');

    [$child, $lockingReadsBefore] = childIntentStaleContextDispatch($runId);

    $context = childIntentStaleContextCurrent($runId);
    $history = app(RunHistoryStore::class)->find($runId);

    expect($context['data']['fan_out_step'] ?? null)->toBe('fan-out-output', json_encode($context))
        ->and($context['metadata']['fan_out_marker'] ?? null)->toBe('saved-by-fan-out')
        ->and(collect($context['artifacts'])->firstWhere('name', 'fan-out-artifact')['content'] ?? null)->toBe('fan-out-artifact-content')
        ->and($history['context']['data']['fan_out_step'] ?? null)->toBe('fan-out-output', json_encode($history['context'] ?? null))
        ->and(collect($history['context']['artifacts'] ?? [])->firstWhere('name', 'fan-out-artifact')['content'] ?? null)->toBe('fan-out-artifact-content')
        ->and($history['metadata']['fan_out_marker'] ?? null)->toBe('saved-by-fan-out')
        ->and($store->lockingReads[$runId] ?? 0)->toBeGreaterThan($lockingReadsBefore);

    childIntentStaleContextAssertChildRecorded($runId, $child, $context, $child->childRunId);
});

test('a child intent recorded from a stale read keeps a signal accepted on another wait since, and records the child under its dedupe key (#650)', function (): void {
    $runId = childIntentStaleContextParent();
    $store = childIntentStaleContextStore();
    $store->takeSnapshot($runId);

    $result = app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved_by' => 'reviewer-650']);

    expect($result->accepted)->toBeTrue()
        ->and($store->find($runId)['metadata'])->not->toHaveKey('durable_signals');

    [$child, $lockingReadsBefore] = childIntentStaleContextDispatch($runId, 'review:650');

    $context = childIntentStaleContextCurrent($runId);
    $history = app(RunHistoryStore::class)->find($runId);
    $signal = ['payload' => ['approved_by' => 'reviewer-650'], 'signal_id' => (int) $result->signal['id']];
    $outcome = ['status' => 'signalled', 'payload' => ['approved_by' => 'reviewer-650'], 'timed_out' => false];

    expect($context['metadata']['durable_signals']['approval'] ?? null)->toBe($signal, json_encode($context['metadata']))
        ->and($context['metadata']['durable_wait_outcomes']['approval'] ?? null)->toBe($outcome)
        ->and($history['metadata']['durable_signals']['approval'] ?? null)->toBe($signal)
        ->and($history['metadata']['durable_wait_outcomes']['approval'] ?? null)->toBe($outcome)
        ->and($context['metadata']['durable_dispatched_child_swarms'] ?? null)->toBe(['review:650' => true])
        ->and($store->lockingReads[$runId] ?? 0)->toBeGreaterThan($lockingReadsBefore);

    childIntentStaleContextAssertChildRecorded($runId, $child, $context, 'review:650');
});

test('a child intent whose parent context is missing records nothing: the wait and lineage written before the read roll back (#650)', function (): void {
    $runId = childIntentStaleContextParent();
    $generation = DB::table('swarm_durable_runs')->where('run_id', $runId)->value('wait_generation');
    $waits = DB::table('swarm_durable_waits')->where('run_id', $runId)->orderBy('id')->pluck('name')->all();
    DB::table('swarm_contexts')->where('run_id', $runId)->delete();

    expect(fn () => app(DurableSwarmManager::class)->dispatchChildSwarm($runId, FakeSequentialSwarm::class, 'child-task'))
        ->toThrow(SwarmException::class, "Durable run [{$runId}] is missing its persisted context.");

    // The child's wait and lineage row are written before the parent context is read;
    // the failed read rolls them back with the rest of the transaction.
    expect(DB::table('swarm_durable_waits')->where('run_id', $runId)->orderBy('id')->pluck('name')->all())->toBe($waits)
        ->and(DB::table('swarm_durable_runs')->where('run_id', $runId)->value('wait_generation'))->toBe($generation)
        ->and(DB::table('swarm_durable_child_runs')->where('parent_run_id', $runId)->count())->toBe(0)
        ->and(DB::table('swarm_durable_runs')->where('parent_run_id', $runId)->count())->toBe(0);
});
