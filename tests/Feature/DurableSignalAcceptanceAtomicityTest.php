<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSignalHandler;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\DecoratingDurableRunStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * A signal's acceptance is one transaction with its signal row (#611): an acceptance that
 * aborts leaves no signal behind, so the same idempotency key is accepted when sent again,
 * exactly once; every outcome that does not abort returns what it returned before.
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
 * A context store whose writes fail while $failing is set: the acceptance has closed the
 * wait and recorded the signal by the time it publishes the context.
 */
function signalAtomicityFailingContextStore(): ContextStore
{
    return new class(app(ContextStore::class)) implements ContextStore
    {
        public bool $failing = true;

        public function __construct(private readonly ContextStore $inner) {}

        public function put(RunContext $context, int $ttlSeconds): void
        {
            if ($this->failing) {
                throw new RuntimeException('context write failed');
            }

            $this->inner->put($context, $ttlSeconds);
        }

        public function find(string $runId): ?array
        {
            return $this->inner->find($runId);
        }
    };
}

function signalAtomicityHandler(array $with = []): DurableSignalHandler
{
    return app()->makeWith(DurableSignalHandler::class, $with);
}

function signalAtomicityWaitingRun(): string
{
    $runId = FakeSequentialSwarm::make()->dispatchDurable('signal-atomicity-task')->runId;
    app(DurableSwarmManager::class)->wait($runId, 'approval');

    return $runId;
}

function signalAtomicityAssertUntouched(string $runId): void
{
    expect(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(0)
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->value('status'))->toBe('waiting')
        ->and(DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status'))->toBe('waiting');
}

function signalAtomicityAssertAcceptedOnce(string $runId): void
{
    $second = app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'key-1');
    $third = app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'key-1');

    expect($second->accepted)->toBeTrue()
        ->and($second->duplicate)->toBeFalse()
        ->and($third->accepted)->toBeFalse()
        ->and($third->duplicate)->toBeTrue()
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(1)
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->value('status'))->toBe('consumed')
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->value('status'))->toBe('signalled')
        ->and(DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status'))->toBe('pending');
}

test('an acceptance that aborts after closing the wait leaves no signal row, and the same key is accepted once when sent again', function (): void {
    $runId = signalAtomicityWaitingRun();
    $handler = signalAtomicityHandler(['contextStore' => signalAtomicityFailingContextStore()]);

    expect(fn () => $handler->signal($runId, 'approval', ['approved' => true], 'key-1'))
        ->toThrow(RuntimeException::class, 'context write failed');

    signalAtomicityAssertUntouched($runId);
    signalAtomicityAssertAcceptedOnce($runId);
});

test('an acceptance that aborts inside an application transaction the application rolls back leaves no signal row', function (): void {
    $runId = signalAtomicityWaitingRun();
    $handler = signalAtomicityHandler(['contextStore' => signalAtomicityFailingContextStore()]);

    DB::beginTransaction();

    try {
        $handler->signal($runId, 'approval', ['approved' => true], 'key-1');
    } catch (RuntimeException) {
    }

    DB::rollBack();

    signalAtomicityAssertUntouched($runId);
    signalAtomicityAssertAcceptedOnce($runId);
});

test('an acceptance that aborts inside an application transaction the application catches and commits leaves no signal row, and keeps the application\'s own writes', function (): void {
    $runId = signalAtomicityWaitingRun();
    $otherRunId = FakeSequentialSwarm::make()->dispatchDurable('unrelated-task')->runId;
    $handler = signalAtomicityHandler(['contextStore' => signalAtomicityFailingContextStore()]);

    DB::beginTransaction();
    app(DurableSwarmManager::class)->updateDetails($otherRunId, ['written' => 'by-the-application']);

    try {
        $handler->signal($runId, 'approval', ['approved' => true], 'key-1');
    } catch (RuntimeException) {
    }

    DB::commit();

    expect(DB::transactionLevel())->toBe(0)
        ->and(app(DurableSwarmManager::class)->inspect($otherRunId)->details)->toBe(['written' => 'by-the-application']);
    signalAtomicityAssertUntouched($runId);
    signalAtomicityAssertAcceptedOnce($runId);
});

test('an accepted signal and a duplicate return the same signal array as before: the recorded row, no internal keys', function (): void {
    $runId = signalAtomicityWaitingRun();

    $accepted = app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'key-1');
    $duplicate = app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'key-1');

    $keys = ['id', 'run_id', 'name', 'status', 'payload', 'idempotency_key', 'consumed_step_index', 'consumed_at', 'created_at', 'updated_at', 'duplicate'];

    expect($accepted->accepted)->toBeTrue()
        ->and($accepted->status)->toBe('accepted')
        ->and(array_keys($accepted->signal))->toBe($keys)
        ->and($accepted->signal['status'])->toBe('recorded')
        ->and($accepted->signal['consumed_at'])->toBeNull()
        ->and($accepted->signal['duplicate'])->toBeFalse()
        ->and($duplicate->accepted)->toBeFalse()
        ->and($duplicate->duplicate)->toBeTrue()
        ->and(array_keys($duplicate->signal))->toBe($keys)
        ->and($duplicate->signal['status'])->toBe('consumed')
        ->and($duplicate->signal['id'])->toBe($accepted->signal['id']);
});

test('a key recorded before its wait opened is still a duplicate once the wait is open, and leaves the wait open', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('early-signal-task')->runId;
    $early = app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'key-1');
    app(DurableSwarmManager::class)->wait($runId, 'approval');

    $resent = app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'key-1');

    expect($early->accepted)->toBeFalse()
        ->and($early->status)->toBe('recorded')
        ->and($resent->accepted)->toBeFalse()
        ->and($resent->duplicate)->toBeTrue()
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(1)
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->value('status'))->toBe('waiting')
        ->and(DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status'))->toBe('waiting');
});

test('a store without the atomic acceptance capability keeps recording the signal before the acceptance', function (): void {
    $runId = signalAtomicityWaitingRun();
    $store = new DecoratingDurableRunStore(app(DurableRunStore::class));
    $failingContext = signalAtomicityFailingContextStore();
    $handler = signalAtomicityHandler(['durableRuns' => $store, 'contextStore' => $failingContext]);

    expect(fn () => $handler->signal($runId, 'approval', ['approved' => true], 'key-1'))
        ->toThrow(RuntimeException::class, 'context write failed');

    // The documented weaker boundary: the signal row was written before the acceptance
    // transaction, so it survives the abort and the same key is then a duplicate.
    $failingContext->failing = false;
    $resent = $handler->signal($runId, 'approval', ['approved' => true], 'key-1');

    expect(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(1)
        ->and($resent->duplicate)->toBeTrue()
        ->and($resent->accepted)->toBeFalse()
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->value('status'))->toBe('waiting');
});
