<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSignalHandler;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\DecoratingDurableRunStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\StaleSnapshotContextStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ChildDispatchingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * #636: a wait closer merges its outcome into the run's context and writes the row
 * back. It reads that context with a locking read, so the context it writes back is
 * the latest committed one, not an older snapshot that predates a fan-out.
 *
 * Each test takes a snapshot of the run's context (StaleSnapshotContextStore: an
 * ordinary find() then keeps answering with it, as under MySQL's REPEATABLE READ),
 * commits a context the way a fan-out would, then closes the wait. The real snapshot
 * case is in tests/ProcessConcurrency/DurableJoinOpenWaitTest.php.
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

function closerStaleContextStore(): StaleSnapshotContextStore
{
    $store = app(ContextStore::class);

    expect($store)->toBeInstanceOf(StaleSnapshotContextStore::class);

    return $store;
}

/**
 * Take the run's snapshot, then commit what a fan-out saves: data, metadata and an
 * artifact the snapshot does not hold. Returns the run's locking-read count at the
 * snapshot: setup before it (a child intent, #650) may already have read with a lock,
 * so the closer's own read is the increase from here.
 */
function closerStaleContextFanOutAfterSnapshot(string $runId): int
{
    $store = closerStaleContextStore();
    $store->takeSnapshot($runId);
    $lockingReads = $store->lockingReads[$runId] ?? 0;

    // A separate, plain store reads the current row, so the locking-read count below
    // is the closer's own.
    $context = RunContext::fromPayload((array) (new DatabaseContextStore(DB::connection(), config(), app(SwarmPersistenceCipher::class)))->find($runId));
    $context->mergeData(['fan_out_step' => 'fan-out-output'])
        ->mergeMetadata(['fan_out_marker' => 'saved-by-fan-out'])
        ->addArtifact(new SwarmArtifact(name: 'fan-out-artifact', content: 'fan-out-artifact-content'));
    $store->put($context, 3600);

    // The snapshot is stale now: an ordinary read still misses the fan-out.
    expect($store->find($runId)['data'])->not->toHaveKey('fan_out_step')
        ->and($store->lockingReads[$runId] ?? 0)->toBe($lockingReads);

    return $lockingReads;
}

/**
 * @return array<string, mixed>
 */
function closerStaleContextAfterClose(string $runId, int $lockingReadsAtSnapshot): array
{
    $store = closerStaleContextStore();
    $store->dropSnapshot($runId);
    $context = (array) $store->find($runId);

    expect($context['data']['fan_out_step'] ?? null)->toBe('fan-out-output', json_encode($context))
        ->and($context['metadata']['fan_out_marker'] ?? null)->toBe('saved-by-fan-out')
        ->and(collect($context['artifacts'])->firstWhere('name', 'fan-out-artifact')['content'] ?? null)->toBe('fan-out-artifact-content')
        ->and($store->lockingReads[$runId] ?? 0)->toBeGreaterThan($lockingReadsAtSnapshot);

    $history = app(RunHistoryStore::class)->find($runId);

    expect($history['context']['data']['fan_out_step'] ?? null)->toBe('fan-out-output')
        ->and($history['metadata']['fan_out_marker'] ?? null)->toBe('saved-by-fan-out');

    return $context;
}

test('a signal accepted from a stale snapshot keeps the context a fan-out committed since, and records its payload (#636)', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('closer-stale-context-task')->runId;
    app(DurableSwarmManager::class)->wait($runId, 'approval');
    $lockingReads = closerStaleContextFanOutAfterSnapshot($runId);

    $result = app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved_by' => 'reviewer-636']);

    $context = closerStaleContextAfterClose($runId, $lockingReads);

    expect($result->accepted)->toBeTrue()
        ->and($context['metadata']['durable_signals']['approval'])->toBe(['payload' => ['approved_by' => 'reviewer-636'], 'signal_id' => (int) $result->signal['id']])
        ->and($context['metadata']['durable_wait_outcomes']['approval'])->toBe(['status' => 'signalled', 'payload' => ['approved_by' => 'reviewer-636'], 'timed_out' => false]);
});

test('a signal accepted by a store without atomic acceptance keeps the context a fan-out committed since (#636)', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('closer-stale-context-task')->runId;
    app(DurableSwarmManager::class)->wait($runId, 'approval');
    $lockingReads = closerStaleContextFanOutAfterSnapshot($runId);

    // Not an AcceptsDurableSignals store: the signal is recorded, then accepted.
    $handler = app()->makeWith(DurableSignalHandler::class, ['durableRuns' => new DecoratingDurableRunStore(app(DurableRunStore::class))]);
    $result = $handler->signal($runId, 'approval', ['approved_by' => 'reviewer-636']);

    $context = closerStaleContextAfterClose($runId, $lockingReads);

    expect($result->accepted)->toBeTrue()
        ->and($context['metadata']['durable_signals']['approval']['payload'])->toBe(['approved_by' => 'reviewer-636'])
        ->and($context['metadata']['durable_wait_outcomes']['approval']['status'])->toBe('signalled');
});

test('a wait timing out from a stale snapshot keeps the context a fan-out committed since, and records its outcome (#636)', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('closer-stale-context-task')->runId;
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'timed_wait', 'Timed wait', 60);
    $this->travel(61)->seconds();
    $lockingReads = closerStaleContextFanOutAfterSnapshot($runId);

    $manager->recover(runId: $runId);

    $context = closerStaleContextAfterClose($runId, $lockingReads);

    expect(DB::table('swarm_durable_waits')->where('run_id', $runId)->value('status'))->toBe('timed_out')
        ->and($context['metadata']['durable_wait_outcomes']['timed_wait'])->toBe(['status' => 'timed_out', 'payload' => null, 'timed_out' => true]);
});

test('a child outcome delivered from a stale snapshot keeps the context the parent committed since, and records the child (#636)', function (): void {
    Queue::fake();
    FakeResearcher::fake(['parent-step', 'child-research']);
    FakeWriter::fake(['parent-final', 'child-writer']);
    FakeEditor::fake(['child-output']);

    $runId = ChildDispatchingSwarm::make()->dispatchDurable('parent-task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $childRunId = (string) $manager->inspect($runId)->children[0]['child_run_id'];

    expect($manager->find($runId)['status'])->toBe('waiting');

    $lockingReads = closerStaleContextFanOutAfterSnapshot($runId);

    foreach ([0, 1, 2] as $step) {
        (new AdvanceDurableSwarm($childRunId, $step))->handle($manager);
    }

    $context = closerStaleContextAfterClose($runId, $lockingReads);

    expect($manager->inspect($runId)->waits[0]['status'])->toBe('child_completed')
        ->and($context['metadata']['durable_child_runs'][$childRunId]['status'])->toBe('completed');
});

test('findForUpdate() reads a context row exactly as find() does, and a missing row as null (#636)', function (): void {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(DatabaseContextStore::class);

    $runId = FakeSequentialSwarm::make()->dispatchDurable('sealed resume prompt')->runId;
    $store = app(DatabaseContextStore::class);
    $context = RunContext::fromPayload((array) $store->find($runId))
        ->mergeData(['step' => 'output'])
        ->mergeMetadata(['marker' => ['nested' => true]])
        ->addArtifact(new SwarmArtifact(name: 'artifact', content: ['k' => 'v'], metadata: ['m' => 1], stepAgentClass: FakeResearcher::class))
        ->setNativeInputReference('native-input-ref-636');
    $store->put($context, 3600);

    expect(DB::table('swarm_contexts')->where('run_id', $runId)->value('input'))->not->toContain('sealed resume prompt')
        ->and($store->findForUpdate($runId))->toBe($store->find($runId))
        ->and($store->findForUpdate($runId)['input'])->toBe('sealed resume prompt')
        ->and($store->findForUpdate($runId)['native_input_ref'])->toBe('native-input-ref-636')
        ->and($store->findForUpdate($runId)['metadata']['marker'])->toBe(['nested' => true])
        ->and($store->findForUpdate('missing-run'))->toBeNull();
});

/**
 * #649: cancel() writes the run's context back and publishes terminal history from it;
 * pause() publishes paused history from it. Both read it with a locking read once the
 * run row is written. On SQLite this proves which read is used and what is persisted
 * when an ordinary read is stale; the real snapshot case is in
 * tests/ProcessConcurrency/DurableJoinOpenWaitTest.php.
 */
function lifecycleStaleContextAssertHistoryKept(string $runId, string $status): void
{
    $history = app(RunHistoryStore::class)->find($runId);

    expect($history['status'] ?? null)->toBe($status)
        ->and($history['context']['data']['fan_out_step'] ?? null)->toBe('fan-out-output', json_encode($history['context'] ?? null))
        ->and($history['metadata']['fan_out_marker'] ?? null)->toBe('saved-by-fan-out')
        ->and(collect($history['context']['artifacts'] ?? [])->firstWhere('name', 'fan-out-artifact')['content'] ?? null)->toBe('fan-out-artifact-content');
}

test('a run cancelled from a stale snapshot keeps the context a fan-out committed since (#649)', function (bool $openWait): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('lifecycle-stale-context-task')->runId;
    $manager = app(DurableSwarmManager::class);

    if ($openWait) {
        $manager->wait($runId, 'approval');
    }

    $lockingReads = closerStaleContextFanOutAfterSnapshot($runId);

    $result = $manager->cancel($runId);

    expect($result->status->value)->toBe('cancelled')
        ->and($manager->find($runId)['status'])->toBe('cancelled');

    closerStaleContextAfterClose($runId, $lockingReads);
    lifecycleStaleContextAssertHistoryKept($runId, 'cancelled');
})->with([
    'pending run' => [false],
    'run waiting on a wait' => [true],
]);

test('a run paused from a stale snapshot publishes history with the context a fan-out committed since (#649)', function (bool $openWait): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('lifecycle-stale-context-task')->runId;
    $manager = app(DurableSwarmManager::class);

    if ($openWait) {
        $manager->wait($runId, 'approval');
    }

    $lockingReads = closerStaleContextFanOutAfterSnapshot($runId);

    $result = $manager->pause($runId);

    expect($result->status->value)->toBe('paused')
        ->and($manager->find($runId)['status'])->toBe('paused');

    lifecycleStaleContextAssertHistoryKept($runId, 'paused');

    expect(closerStaleContextStore()->lockingReads[$runId] ?? 0)->toBeGreaterThan($lockingReads);
})->with([
    'pending run' => [false],
    'run waiting on a wait' => [true],
]);
