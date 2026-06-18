<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use Illuminate\Queue\SyncQueue;

beforeEach(function () {
    config()->set('swarm.durable.step_timeout', 120);
    config()->set('swarm.durable.job.tries', 3);
    config()->set('swarm.durable.job.timeout_margin_seconds', 45);
    config()->set('swarm.durable.job.backoff_seconds', [10, 30, 60]);
});

test('advance durable swarm job exposes tries timeout and backoff from config', function () {
    $job = new AdvanceDurableSwarm('run-1', 0);

    expect($job->tries())->toBe(3)
        ->and($job->timeout)->toBe(165)
        ->and($job->backoff())->toBe([10, 30, 60]);
});

test('advance durable branch job exposes tries timeout and backoff from config', function () {
    $job = new AdvanceDurableBranch('run-1', 'parallel:0');

    expect($job->tries())->toBe(3)
        ->and($job->timeout)->toBe(165)
        ->and($job->backoff())->toBe([10, 30, 60]);
});

test('durable advance job tries are at least one', function () {
    config()->set('swarm.durable.job.tries', 0);

    $swarm = new AdvanceDurableSwarm('r', 0);
    $branch = new AdvanceDurableBranch('r', 'b');

    expect($swarm->tries())->toBe(1)
        ->and($branch->tries())->toBe(1);
});

test('durable job dispatcher still routes connection and queue on step and branch jobs', function () {
    $dispatcher = app(DurableJobDispatcher::class);

    $step = $dispatcher->makeStepJob('run-z', 2, 'redis', 'swarm-durable');
    expect($step->connection)->toBe('redis')
        ->and($step->queue)->toBe('swarm-durable')
        ->and($step->tries())->toBe(3)
        ->and($step->timeout)->toBe(165);

    $branch = $dispatcher->makeBranchJob('run-z', 'parallel:1', 'redis', 'swarm-parallel');
    expect($branch->connection)->toBe('redis')
        ->and($branch->queue)->toBe('swarm-parallel')
        ->and($branch->backoff())->toBe([10, 30, 60]);
});

test('advance durable swarm job timeout property reaches the queue payload', function () {
    // Regression guard: proves the property (not a method) propagates into the
    // serialized queue payload. This is the assertion that would have caught
    // the original inert-method bug — Queue::createObjectPayload reads
    // $job->timeout via getAttributeValue(), which reads a property only.
    $job = new AdvanceDurableSwarm('run-payload-test', 0);

    expect($job->timeout)->toBe(165);

    // SyncQueue::createPayload is protected — expose it via a thin subclass.
    $queue = new class extends SyncQueue
    {
        public function buildPayload(object $job, string $queue): array
        {
            return json_decode($this->createPayload($job, $queue), true);
        }
    };
    $queue->setContainer(app());

    $payload = $queue->buildPayload($job, 'default');

    expect($payload['timeout'])->toBe(165);
});

test('advance durable branch job timeout property reaches the queue payload', function () {
    $job = new AdvanceDurableBranch('run-payload-test', 'parallel:0');

    expect($job->timeout)->toBe(165);

    $queue = new class extends SyncQueue
    {
        public function buildPayload(object $job, string $queue): array
        {
            return json_decode($this->createPayload($job, $queue), true);
        }
    };
    $queue->setContainer(app());

    $payload = $queue->buildPayload($job, 'default');

    expect($payload['timeout'])->toBe(165);
});

test('durable advance job timeout is at least one second', function () {
    config()->set('swarm.durable.step_timeout', 0);
    config()->set('swarm.durable.job.timeout_margin_seconds', 0);

    $swarm = new AdvanceDurableSwarm('r', 0);
    $branch = new AdvanceDurableBranch('r', 'b');

    expect($swarm->timeout)->toBeGreaterThanOrEqual(1)
        ->and($branch->timeout)->toBeGreaterThanOrEqual(1);
});
