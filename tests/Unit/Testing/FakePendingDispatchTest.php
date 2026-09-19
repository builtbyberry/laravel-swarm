<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Testing\FakePendingDispatch;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\EmptyRunnableSwarm;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

dataset('fake response kind', ['queued', 'durable']);

test('fake responses preserve inert fluent routing and proxy boundaries', function (string $kind) {
    Bus::fake();
    $dispatch = new FakePendingDispatch;
    $manager = Mockery::mock(DurableSwarmManager::class);
    $manager->shouldNotReceive('updateQueueRouting');
    $response = $kind === 'queued'
        ? new QueuedSwarmResponse($dispatch, 'fake-run-id')
        : new DurableSwarmResponse($dispatch, $manager, 'fake-run-id');

    $deferred = function (): never {
        throw new LogicException('Deferred work must not execute in an intent fake.');
    };

    foreach ([
        ['onConnection', ['redis']],
        ['onQueue', ['critical']],
        ['onGroup', ['group']],
        ['withDeduplicator', [$deferred]],
        ['allOnConnection', ['redis']],
        ['allOnQueue', ['critical']],
        ['delay', [30]],
        ['withoutDelay', []],
        ['afterCommit', []],
        ['beforeCommit', []],
        ['chain', [[$deferred]]],
        ['afterResponse', []],
        ['afterResponse', [false]],
    ] as [$method, $arguments]) {
        expect($response->{$method}(...$arguments))->toBe($response);
    }

    $conditions = [];
    expect($response->when(true, function (PendingDispatch $pending) use (&$conditions) {
        $conditions[] = 'when';

        return $pending->onQueue('conditional');
    }))->toBe($response);
    expect($response->unless(false, function (PendingDispatch $pending) use (&$conditions) {
        $conditions[] = 'unless';

        return $pending->onConnection('conditional');
    }))->toBe($response);
    expect($conditions)->toBe(['when', 'unless']);

    $job = $response->getJob();
    expect($dispatch)->toBeInstanceOf(PendingDispatch::class)
        ->and($job)->toBeObject()
        ->and(get_object_vars($job))->toBe([])
        ->and(method_exists($job, 'handle'))->toBeFalse()
        ->and($response->runId)->toBe('fake-run-id');

    foreach (['then', 'catch', 'unknownMethod'] as $method) {
        expect(fn () => $response->{$method}($deferred))->toThrow(
            BadMethodCallException::class,
            "Method [{$method}] does not exist on the {$kind} swarm response.",
        );
    }

    unset($response, $dispatch);
    gc_collect_cycles();
    Bus::assertNothingDispatched();
})->with('fake response kind');

test('fake destruction never dispatches including after response and collected cycles', function (bool $afterResponse, bool $cyclic) {
    Bus::fake();
    Queue::fake();
    $dispatch = (new FakePendingDispatch)->afterResponse($afterResponse);
    $weak = WeakReference::create($dispatch);

    if ($cyclic) {
        // A closure held in the inherited flag makes a genuine reference cycle.
        // No production API is added to the fake solely to create the test cycle.
        (new ReflectionProperty(PendingDispatch::class, 'afterResponse'))
            ->setValue($dispatch, fn () => $dispatch);
    }

    unset($dispatch);
    gc_collect_cycles();

    expect($weak->get())->toBeNull();
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
})->with([false, true])->with([false, true]);

test('swarm queued and durable fakes record intent with no runtime side effects', function () {
    Bus::fake();
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake();
    $audit = Mockery::mock(SwarmAuditDispatcher::class);
    $audit->shouldNotReceive('emit');
    app()->instance(SwarmAuditDispatcher::class, $audit);
    config()->set('swarm.persistence.driver', 'database');
    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();

    EmptyRunnableSwarm::fake();
    $swarm = EmptyRunnableSwarm::make();
    $queued = $swarm->queue('queued')->onConnection('redis')->onQueue('critical')->afterResponse();
    $durable = $swarm->dispatchDurable('durable')->onConnection('redis')->onQueue('critical')->afterCommit();

    expect($queued)->toBeInstanceOf(QueuedSwarmResponse::class)
        ->and($durable)->toBeInstanceOf(DurableSwarmResponse::class)
        ->and($durable->signal('approved')->accepted)->toBeTrue();
    $durable->pause();
    $durable->resume();
    $durable->cancel();
    expect($durable->inspect()->run['status'])->toBe('fake');
    EmptyRunnableSwarm::assertQueued('queued');
    EmptyRunnableSwarm::assertDispatchedDurably('durable');
    EmptyRunnableSwarm::assertDurableSignalled('approved');

    unset($queued, $durable);
    gc_collect_cycles();

    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(DB::connection()->getQueryLog())->toBe([]);
});
