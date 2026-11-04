<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\SwarmSignalCommand;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmOperator;
use BuiltByBerry\LaravelSwarm\Enums\DurableDispatchType;
use BuiltByBerry\LaravelSwarm\Enums\DurableLifecycleStatus;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSignalHandler;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Support\SwarmWebhooks;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Http\WrapsRequestInDatabaseTransaction;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\DecoratingDurableRunStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableWaitAttributeSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager as TestingTransactionsManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;

/**
 * A signal delivered to a paused durable run whose wait of that name is open is accepted at
 * delivery (#601): the wait closes with it, the run stays paused, and resume continues the
 * run. Accepted only outside an application transaction; inside one, and with a store
 * without the atomic acceptance capability, the signal is recorded and not accepted.
 */
beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

/**
 * A run of DurableWaitAttributeSwarm that has run its first step and is waiting on its
 * declared `approval_received` wait (timeout 3600s).
 *
 * @return array{0: string, 1: DurableSwarmManager}
 */
function pausedSignalWaitingRun(bool $eager = false): array
{
    config()->set('swarm.durable.eager_dispatch', $eager);
    Queue::fake();
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);

    $runId = DurableWaitAttributeSwarm::make()->dispatchDurable('wait-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('waiting');

    return [$runId, $manager];
}

/**
 * Step continuations for the run: committed outbox step entries and step jobs pushed.
 *
 * @return array{outbox: int, pushed: int}
 */
function pausedSignalStepIntents(string $runId): array
{
    $pushed = 0;

    foreach (Queue::pushedJobs() as $pushes) {
        foreach ($pushes as $push) {
            if ($push['job'] instanceof AdvanceDurableSwarm && $push['job']->runId === $runId) {
                $pushed++;
            }
        }
    }

    return [
        'outbox' => DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', DurableDispatchType::Step->value)->count(),
        'pushed' => $pushed,
    ];
}

/**
 * @return array<string, mixed>
 */
function pausedSignalWaitRow(string $runId, string $name): array
{
    return (array) DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', $name)->orderByDesc('id')->first();
}

/**
 * @return array<string, mixed>
 */
function pausedSignalContextMetadata(string $runId): array
{
    return app(ContextStore::class)->find($runId)['metadata'] ?? [];
}

function pausedSignalSignalStatus(int $signalId): string
{
    return (string) DB::table('swarm_durable_signals')->where('id', $signalId)->value('status');
}

test('a signal delivered while paused is accepted at delivery, the run stays paused, and resume continues it', function (bool $eager) {
    [$runId, $manager] = pausedSignalWaitingRun($eager);

    $manager->pause($runId);
    $beforeSignal = pausedSignalStepIntents($runId);

    $whilePaused = $manager->signal($runId, 'approval_received', ['approved' => true], 'while-paused');
    $signalId = (int) $whilePaused->signal['id'];

    expect($whilePaused->accepted)->toBeTrue()
        ->and($whilePaused->status)->toBe('accepted')
        ->and($whilePaused->duplicate)->toBeFalse()
        ->and($manager->find($runId)['status'])->toBe('paused')
        ->and(app(SwarmHistory::class)->find($runId)['status'])->toBe('paused')
        ->and(pausedSignalWaitRow($runId, 'approval_received')['status'])->toBe('signalled')
        ->and((int) pausedSignalWaitRow($runId, 'approval_received')['signal_id'])->toBe($signalId)
        ->and(pausedSignalContextMetadata($runId)['durable_signals']['approval_received']['signal_id'])->toBe($signalId)
        ->and(pausedSignalSignalStatus($signalId))->toBe('consumed')
        // Nothing continues while the run is paused.
        ->and(pausedSignalStepIntents($runId))->toBe($beforeSignal);

    // swarm:recover leaves a paused run whose wait closed alone.
    $manager->recover(runId: $runId);

    expect($manager->find($runId)['status'])->toBe('paused')
        ->and(pausedSignalStepIntents($runId))->toBe($beforeSignal);

    $sameKey = $manager->signal($runId, 'approval_received', ['approved' => true], 'while-paused');

    expect($sameKey->duplicate)->toBeTrue()
        ->and($sameKey->accepted)->toBeFalse()
        ->and($manager->find($runId)['status'])->toBe('paused');

    $resumed = $manager->resume($runId);
    $afterResume = pausedSignalStepIntents($runId);

    expect($resumed->status)->toBe(DurableLifecycleStatus::Resumed)
        ->and($resumed->isWaiting())->toBeFalse()
        ->and($manager->find($runId)['status'])->toBe('pending')
        ->and($manager->find($runId)['next_step_index'])->toBe(1)
        ->and($afterResume['outbox'] + $afterResume['pushed'])->toBeGreaterThan($beforeSignal['outbox'] + $beforeSignal['pushed'])
        ->and($afterResume['outbox'])->toBe($beforeSignal['outbox'] + 1);

    // The interim workaround (#592): the same delivery re-sent with a new key after resume.
    // The wait it was meant for is already satisfied, so it is recorded and changes nothing.
    $newKey = $manager->signal($runId, 'approval_received', ['approved' => false], 'after-resume');

    expect($newKey->accepted)->toBeFalse()
        ->and($newKey->duplicate)->toBeFalse()
        ->and($newKey->status)->toBe('recorded')
        ->and($manager->find($runId)['status'])->toBe('pending')
        ->and($manager->find($runId)['next_step_index'])->toBe(1)
        ->and(pausedSignalStepIntents($runId))->toBe($afterResume)
        ->and((int) pausedSignalWaitRow($runId, 'approval_received')['signal_id'])->toBe($signalId)
        ->and(pausedSignalContextMetadata($runId)['durable_signals']['approval_received'])->toBe(['payload' => ['approved' => true], 'signal_id' => $signalId])
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(2)
        ->and(pausedSignalSignalStatus((int) $newKey->signal['id']))->toBe('recorded')
        ->and(pausedSignalSignalStatus($signalId))->toBe('consumed');

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('completed');
})->with(['relay dispatch' => false, 'eager dispatch' => true]);

test('a same-name signal recorded before the wait opened never satisfies it, on pause or resume', function () {
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('early-signal-task')->runId;
    $manager = app(DurableSwarmManager::class);

    $early = $manager->signal($runId, 'approval', ['n' => 1], 'early');

    expect($early->accepted)->toBeFalse()
        ->and($early->status)->toBe('recorded');

    $manager->wait($runId, 'approval');
    $manager->pause($runId);
    $resumed = $manager->resume($runId);

    expect($resumed->isWaiting())->toBeTrue()
        ->and($manager->find($runId)['status'])->toBe('waiting')
        ->and(pausedSignalWaitRow($runId, 'approval')['status'])->toBe('waiting')
        ->and(pausedSignalWaitRow($runId, 'approval')['signal_id'])->toBeNull()
        ->and(pausedSignalSignalStatus((int) $early->signal['id']))->toBe('recorded');
});

test('a fresh delivery while paused satisfies the wait, never an earlier same-name signal', function () {
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('fresh-signal-task')->runId;
    $manager = app(DurableSwarmManager::class);

    $early = $manager->signal($runId, 'approval', ['n' => 1], 'early');
    $manager->wait($runId, 'approval');
    $manager->pause($runId);

    $fresh = $manager->signal($runId, 'approval', ['n' => 2], 'fresh');

    expect($fresh->accepted)->toBeTrue()
        ->and((int) pausedSignalWaitRow($runId, 'approval')['signal_id'])->toBe((int) $fresh->signal['id'])
        ->and(pausedSignalContextMetadata($runId)['durable_signals']['approval']['payload'])->toBe(['n' => 2])
        ->and(pausedSignalSignalStatus((int) $early->signal['id']))->toBe('recorded')
        ->and(pausedSignalSignalStatus((int) $fresh->signal['id']))->toBe('consumed');
});

test('signalling one of two open waits while paused keeps the run paused, and resume waits on the other', function () {
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('two-waits-task')->runId;
    $manager = app(DurableSwarmManager::class);

    $manager->wait($runId, 'first', 'First wait');
    $manager->wait($runId, 'second', 'Second wait', 60);
    $manager->pause($runId);

    expect($manager->signal($runId, 'second', ['ok' => true], 'second-key')->accepted)->toBeTrue()
        ->and($manager->find($runId)['status'])->toBe('paused')
        ->and($manager->find($runId)['wait_reason'])->toBe('First wait')
        ->and($manager->find($runId)['wait_timeout_at'])->toBeNull();

    $resumed = $manager->resume($runId);

    expect($resumed->isWaiting())->toBeTrue()
        ->and($manager->find($runId)['status'])->toBe('waiting')
        ->and($manager->find($runId)['wait_reason'])->toBe('First wait')
        ->and(pausedSignalWaitRow($runId, 'first')['status'])->toBe('waiting');
});

test('the wait summary is cleared when the last wait of a paused run closes, by a signal or a child outcome', function (string $closer) {
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('summary-task')->runId;
    $manager = app(DurableSwarmManager::class);

    $manager->wait($runId, 'only', 'Only wait', 60);
    $manager->pause($runId);

    expect($manager->find($runId)['wait_reason'])->toBe('Only wait');

    if ($closer === 'signal') {
        expect($manager->signal($runId, 'only', null, 'only-key')->accepted)->toBeTrue();
    } else {
        app(DurableRunStore::class)->releaseWaitWithOutcome($runId, 'only', 'child_completed', ['status' => 'completed', 'timed_out' => false]);
    }

    $run = $manager->find($runId);

    expect($run['status'])->toBe('paused')
        ->and($run['wait_reason'])->toBeNull()
        ->and($run['waiting_since'])->toBeNull()
        ->and($run['wait_timeout_at'])->toBeNull();
})->with(['signal' => 'signal', 'child outcome' => 'child']);

test('a signal sent from an after-commit callback of a committed transaction is accepted while paused', function () {
    [$runId, $manager] = pausedSignalWaitingRun();
    $manager->pause($runId);

    $result = null;
    $level = null;

    DB::transaction(function () use ($runId, &$result, &$level): void {
        DB::afterCommit(function () use ($runId, &$result, &$level): void {
            $level = DB::connection()->transactionLevel();
            $result = app(SwarmOperator::class)->signal($runId, 'approval_received', ['approved' => true], 'after-commit');
        });
    });

    expect($level)->toBe(0)
        ->and($result?->accepted)->toBeTrue()
        ->and(pausedSignalWaitRow($runId, 'approval_received')['status'])->toBe('signalled')
        ->and($manager->find($runId)['status'])->toBe('paused');
});

test('under the testing transaction manager an after-commit signal still runs inside the wrapper and is recorded, not accepted', function () {
    [$runId, $manager] = pausedSignalWaitingRun();
    $manager->pause($runId);
    $before = pausedSignalStepIntents($runId);

    $connection = DB::connection();
    $original = app('db.transactions');
    $testing = new TestingTransactionsManager([$connection->getName()]);
    app()->instance('db.transactions', $testing);
    $connection->setTransactionManager($testing);

    $result = null;
    $level = null;

    try {
        $connection->beginTransaction(); // the RefreshDatabase-style wrapper

        DB::transaction(function () use ($runId, &$result, &$level): void {
            DB::afterCommit(function () use ($runId, &$result, &$level): void {
                $level = DB::connection()->transactionLevel();
                $result = app(SwarmOperator::class)->signal($runId, 'approval_received', ['approved' => true], 'testing-wrapper');
            });
        });

        expect($level)->toBe(1)
            ->and($result?->accepted)->toBeFalse()
            ->and($result?->status)->toBe('recorded')
            ->and(pausedSignalWaitRow($runId, 'approval_received')['status'])->toBe('waiting')
            ->and(pausedSignalContextMetadata($runId)['durable_wait_outcomes'] ?? [])->not->toHaveKey('approval_received')
            ->and(pausedSignalStepIntents($runId))->toBe($before);
    } finally {
        $connection->rollBack();
        app()->instance('db.transactions', $original);
        $connection->setTransactionManager($original);
    }
});

test('the signal webhook under a transaction-wrapping middleware records a paused run\'s signal without accepting it', function () {
    config()->set('swarm.durable.webhooks.enabled', true);
    config()->set('swarm.durable.webhooks.auth.driver', 'token');
    config()->set('swarm.durable.webhooks.auth.token', 'test-token');

    Route::middleware(WrapsRequestInDatabaseTransaction::class)->group(function (): void {
        SwarmWebhooks::routes([]);
    });

    [$runId, $manager] = pausedSignalWaitingRun();
    $manager->pause($runId);

    $this->postJson("/swarm/webhooks/signal/{$runId}/approval_received", ['approved' => true], [
        'Authorization' => 'Bearer test-token',
        'Idempotency-Key' => 'webhook-in-transaction',
    ])->assertStatus(200)->assertJson(['accepted' => false, 'status' => 'recorded']);

    expect(pausedSignalWaitRow($runId, 'approval_received')['status'])->toBe('waiting');
});

test('the signal webhook outside any transaction accepts a paused run\'s signal', function () {
    config()->set('swarm.durable.webhooks.enabled', true);
    config()->set('swarm.durable.webhooks.auth.driver', 'token');
    config()->set('swarm.durable.webhooks.auth.token', 'test-token');

    SwarmWebhooks::routes([]);

    [$runId, $manager] = pausedSignalWaitingRun();
    $manager->pause($runId);

    $this->postJson("/swarm/webhooks/signal/{$runId}/approval_received", ['approved' => true], [
        'Authorization' => 'Bearer test-token',
        'Idempotency-Key' => 'webhook-outside',
    ])->assertStatus(202)->assertJson(['accepted' => true, 'status' => 'accepted']);

    expect($manager->find($runId)['status'])->toBe('paused');
});

test('a wait signalled while paused is not a timeout candidate even after its timeout passes', function () {
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('timeout-candidate-task')->runId;
    $manager = app(DurableSwarmManager::class);

    $manager->wait($runId, 'a', 'Timed wait', 60);
    $manager->wait($runId, 'b', 'Open wait', 7200);
    $manager->pause($runId);

    expect($manager->signal($runId, 'a', null, 'a-key')->accepted)->toBeTrue();

    $manager->resume($runId);

    // The run is still waiting (on b), so the run-status filter cannot hide `a`.
    expect($manager->find($runId)['status'])->toBe('waiting');

    $this->travel(120)->seconds();

    expect(collect(app(DurableRunStore::class)->recoverableTimedOutWaits($runId))->pluck('wait_name')->all())->toBe([]);
});

test('a direct timeout release cannot turn a wait signalled while paused into a timeout', function () {
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('timeout-release-task')->runId;
    $manager = app(DurableSwarmManager::class);

    $manager->wait($runId, 'a', 'Timed wait', 60);
    $manager->pause($runId);
    $manager->signal($runId, 'a', ['ok' => true], 'a-key');
    $this->travel(120)->seconds();

    expect(app(DurableRunStore::class)->releaseTimedOutWait($runId, 'a'))->toBeFalse()
        ->and(pausedSignalWaitRow($runId, 'a')['status'])->toBe('signalled');
});

test('a signal delivered while paused beats a timeout that passed during the pause', function () {
    [$runId, $manager] = pausedSignalWaitingRun();
    $manager->pause($runId);
    $this->travel(3601)->seconds();

    expect($manager->signal($runId, 'approval_received', ['approved' => true], 'late')->accepted)->toBeTrue();

    $beforeRecover = pausedSignalStepIntents($runId);
    $manager->recover(runId: $runId);
    $beforeResume = pausedSignalStepIntents($runId);

    // swarm:recover leaves a paused run whose wait closed alone, even past every grace.
    expect($manager->find($runId)['status'])->toBe('paused')
        ->and($beforeResume)->toBe($beforeRecover);

    $manager->resume($runId);
    $manager->recover(runId: $runId);

    expect(pausedSignalStepIntents($runId)['outbox'])->toBe($beforeResume['outbox'] + 1);

    expect(pausedSignalWaitRow($runId, 'approval_received')['status'])->toBe('signalled')
        ->and(pausedSignalContextMetadata($runId)['durable_wait_outcomes']['approval_received']['status'])->toBe('signalled')
        ->and($manager->find($runId)['status'])->toBe('pending');
});

test('a paused run\'s signal is recorded, not accepted, when it cannot be accepted atomically', function (string $path) {
    [$runId, $manager] = pausedSignalWaitingRun();
    $manager->pause($runId);
    $before = pausedSignalStepIntents($runId);

    $result = $path === 'transaction'
        ? DB::transaction(fn () => app(SwarmOperator::class)->signal($runId, 'approval_received', ['approved' => true], 'in-transaction'))
        : app()->makeWith(DurableSignalHandler::class, ['durableRuns' => new DecoratingDurableRunStore(app(DurableRunStore::class))])->signal($runId, 'approval_received', ['approved' => true], 'fallback');

    expect($result->accepted)->toBeFalse()
        ->and($result->duplicate)->toBeFalse()
        ->and($result->status)->toBe('recorded')
        ->and(pausedSignalWaitRow($runId, 'approval_received')['status'])->toBe('waiting')
        ->and($manager->find($runId)['status'])->toBe('paused')
        ->and(pausedSignalStepIntents($runId))->toBe($before);

    expect($manager->resume($runId)->isWaiting())->toBeTrue()
        ->and($manager->find($runId)['status'])->toBe('waiting');
})->with(['inside an application transaction' => 'transaction', 'store without the acceptance capability' => 'store']);

test('a paused run\'s signal recorded because it was sent inside a transaction is logged, and an accepted one is not', function () {
    [$runId, $manager] = pausedSignalWaitingRun();
    $manager->pause($runId);
    $logger = Mockery::spy(LoggerInterface::class);
    $handler = app()->makeWith(DurableSignalHandler::class, ['logger' => $logger]);

    DB::transaction(fn () => $handler->signal($runId, 'approval_received', null, 'in-transaction'));

    $logger->shouldHaveReceived('notice')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'inside a database transaction')
        && $context === ['run_id' => $runId, 'signal_name' => 'approval_received']);

    $handler->signal($runId, 'approval_received', null, 'outside');

    $logger->shouldHaveReceived('notice')->once();
});

test('swarm:signal says what happened to the signal and to the run', function () {
    [$runId, $manager] = pausedSignalWaitingRun();
    $manager->pause($runId);

    Artisan::call('swarm:signal', ['runId' => $runId, 'name' => 'approval_received', '--idempotency-key' => 'cmd-1']);
    expect(Artisan::output())->toContain('Signal accepted. The run is paused and continues when it is resumed.');

    Artisan::call('swarm:signal', ['runId' => $runId, 'name' => 'approval_received', '--idempotency-key' => 'cmd-1']);
    expect(Artisan::output())->toContain('Duplicate signal: this idempotency key is already recorded for the run. Nothing changed.');

    // The wait already closed with cmd-1: re-sending, before or after resume, applies nothing.
    Artisan::call('swarm:signal', ['runId' => $runId, 'name' => 'approval_received', '--idempotency-key' => 'cmd-2']);
    expect(Artisan::output())->toContain('Signal recorded. The wait named [approval_received] already closed (signalled); this signal is not applied.')
        ->not->toContain('re-send');

    $manager->resume($runId);

    Artisan::call('swarm:signal', ['runId' => $runId, 'name' => 'approval_received', '--idempotency-key' => 'cmd-3']);
    expect(Artisan::output())->toContain('The wait named [approval_received] already closed (signalled)');

    Artisan::call('swarm:signal', ['runId' => $runId, 'name' => 'no_such_wait', '--idempotency-key' => 'cmd-4']);
    expect(Artisan::output())->toContain('Signal recorded. No open wait named [no_such_wait] accepted it.');

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    Artisan::call('swarm:signal', ['runId' => $runId, 'name' => 'approval_received', '--idempotency-key' => 'cmd-5']);
    expect(Artisan::output())->toContain('Signal recorded. The run is [completed]; no wait will accept it.');
});

test('swarm:signal names why a paused run\'s open wait did not accept the signal', function (string $cause) {
    [$runId, $manager] = pausedSignalWaitingRun();
    $manager->pause($runId);

    if ($cause === 'store') {
        // The durable graph hands its signal handler the store it was built with, so a
        // store without the capability is given to the command alone, with a manager that
        // reports what such a store's signal() returns for a paused run's open wait.
        $decorating = new DecoratingDurableRunStore(app(DurableRunStore::class));
        app()->when(SwarmSignalCommand::class)->needs(DurableRunStore::class)->give(fn () => $decorating);
        $real = app(DurableSwarmManager::class);
        $mock = Mockery::mock(DurableSwarmManager::class);
        $mock->shouldReceive('signal')->once()->andReturn(new DurableSignalResult($runId, 'approval_received', 'recorded', false));
        $mock->shouldReceive('inspect')->once()->andReturn($real->inspect($runId));
        app()->instance(DurableSwarmManager::class, $mock);
        Artisan::call('swarm:signal', ['runId' => $runId, 'name' => 'approval_received', '--idempotency-key' => 'cause']);
        $expected = "The run is paused and the run's durable store cannot accept a signal while the run is paused.";
    } else {
        DB::transaction(fn () => Artisan::call('swarm:signal', ['runId' => $runId, 'name' => 'approval_received', '--idempotency-key' => 'cause']));
        $expected = 'The run is paused and the signal was sent inside a database transaction.';
    }

    expect(Artisan::output())->toContain('Signal recorded but not accepted.')
        ->toContain($expected)
        ->toContain('re-send it after the run is resumed, with a new idempotency key');
})->with(['inside a database transaction' => 'transaction', 'store without the acceptance capability' => 'store']);

test('swarm:signal reports a run still waiting and a run released', function () {
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('cmd-waiting-task')->runId;
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'first');
    $manager->wait($runId, 'second');

    Artisan::call('swarm:signal', ['runId' => $runId, 'name' => 'first']);
    expect(Artisan::output())->toContain('Signal accepted. The run is still waiting (on another wait or on its branches).');

    Artisan::call('swarm:signal', ['runId' => $runId, 'name' => 'second']);
    expect(Artisan::output())->toContain('Signal accepted and run released.');
});

test('swarm:signal describes the runs it signals', function () {
    Artisan::call('list', ['namespace' => 'swarm']);

    expect(Artisan::output())->toContain('Send a signal to a waiting or paused durable swarm run');
});
