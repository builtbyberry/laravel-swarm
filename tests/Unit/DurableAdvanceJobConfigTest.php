<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;

beforeEach(function () {
    config()->set('swarm.durable.step_timeout', 120);
    config()->set('swarm.durable.job.tries', 3);
    config()->set('swarm.durable.job.timeout_margin_seconds', 45);
    config()->set('swarm.durable.job.backoff_seconds', [10, 30, 60]);
});

test('advance durable swarm job exposes tries timeout and backoff from config', function () {
    $job = new AdvanceDurableSwarm('run-1', 0);

    expect($job->tries())->toBe(3)
        ->and($job->timeout())->toBe(165)
        ->and($job->backoff())->toBe([10, 30, 60]);
});

test('advance durable branch job exposes tries timeout and backoff from config', function () {
    $job = new AdvanceDurableBranch('run-1', 'parallel:0');

    expect($job->tries())->toBe(3)
        ->and($job->timeout())->toBe(165)
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
        ->and($step->timeout())->toBe(165);

    $branch = $dispatcher->makeBranchJob('run-z', 'parallel:1', 'redis', 'swarm-parallel');
    expect($branch->connection)->toBe('redis')
        ->and($branch->queue)->toBe('swarm-parallel')
        ->and($branch->backoff())->toBe([10, 30, 60]);
});
