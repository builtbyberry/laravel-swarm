<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmOperator;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\DurableCancelResult;
use BuiltByBerry\LaravelSwarm\Responses\DurablePauseResult;
use BuiltByBerry\LaravelSwarm\Responses\DurableResumeResult;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSwarmOperator;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;

beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([
        ContextStore::class,
        ArtifactRepository::class,
        RunHistoryStore::class,
        DurableRunStore::class,
        SwarmRunner::class,
        DurableSwarmManager::class,
        SwarmOperator::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }
});

test('SwarmOperator resolves from the container as the thin durable adapter', function () {
    $operator = app(SwarmOperator::class);

    expect($operator)->toBeInstanceOf(DurableSwarmOperator::class);

    // The @internal manager must NOT implement the public contract.
    expect(app(DurableSwarmManager::class))->not->toBeInstanceOf(SwarmOperator::class);

    // Stateless singleton (Octane-safe): the same instance resolves twice.
    expect(app(SwarmOperator::class))->toBe($operator);
});

test('SwarmOperator pause returns a rich result reporting the effective status', function () {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('operator-task')->runId;

    $result = app(SwarmOperator::class)->pause($runId);

    expect($result)->toBeInstanceOf(DurablePauseResult::class)
        ->and($result->runId)->toBe($runId)
        ->and($result->status)->toBe('paused')
        ->and($result->isImmediate())->toBeTrue()
        ->and(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('paused');
});

test('SwarmOperator resume returns a rich result and re-dispatches the run', function () {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('operator-task')->runId;

    app(SwarmOperator::class)->pause($runId);
    $result = app(SwarmOperator::class)->resume($runId);

    expect($result)->toBeInstanceOf(DurableResumeResult::class)
        ->and($result->runId)->toBe($runId)
        ->and($result->status)->toBe('resumed')
        ->and($result->isWaiting())->toBeFalse()
        ->and($result->waitingBoundaryDispatched)->toBeFalse()
        ->and(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('pending');
});

test('SwarmOperator cancel returns a rich result reporting the effective status', function () {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('operator-task')->runId;

    $result = app(SwarmOperator::class)->cancel($runId);

    expect($result)->toBeInstanceOf(DurableCancelResult::class)
        ->and($result->runId)->toBe($runId)
        ->and($result->status)->toBe('cancelled')
        ->and($result->isImmediate())->toBeTrue()
        ->and(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('cancelled');
});

test('SwarmOperator signal returns the existing DurableSignalResult shape', function () {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('operator-task')->runId;

    $result = app(SwarmOperator::class)->signal($runId, 'approve', ['ok' => true], 'idem-1');

    expect($result)->toBeInstanceOf(DurableSignalResult::class)
        ->and($result->runId)->toBe($runId)
        ->and($result->name)->toBe('approve');
});

test('SwarmOperator recover returns the redispatched run ids', function () {
    $ids = app(SwarmOperator::class)->recover();

    expect($ids)->toBeArray();
});

test('SwarmOperator verbs fail loud on an unknown run id — never a silent no-op', function () {
    $operator = app(SwarmOperator::class);

    expect(fn () => $operator->pause('does-not-exist'))->toThrow(SwarmException::class);
    expect(fn () => $operator->resume('does-not-exist'))->toThrow(SwarmException::class);
    expect(fn () => $operator->cancel('does-not-exist'))->toThrow(SwarmException::class);
    expect(fn () => $operator->signal('does-not-exist', 'approve'))->toThrow(SwarmException::class);
});

test('operational reads stay on the public SwarmHistory path, not the control contract', function () {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('operator-task')->runId;

    // The public read row already carries status for an operator console; the
    // control contract deliberately exposes no read verbs.
    expect(app(SwarmHistory::class)->find($runId)['status'])->toBe('pending')
        ->and(method_exists(app(SwarmOperator::class), 'find'))->toBeFalse()
        ->and(method_exists(app(SwarmOperator::class), 'inspect'))->toBeFalse();
});
