<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\Concerns\ConfiguresDurableAdvanceJob;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use Illuminate\Broadcasting\Channel;

beforeEach(function () {
    config()->set('swarm.queue.tries', 1);
    config()->set('swarm.queue.timeout', null);
    config()->set('swarm.durable.job.tries', 3);
    config()->set('swarm.durable.step_timeout', 300);
    config()->set('swarm.durable.job.timeout_margin_seconds', 60);
    config()->set('swarm.durable.job.backoff_seconds', [10, 30, 60]);
});

// --- InvokeSwarm ---

test('invoke swarm job tries defaults to 1', function () {
    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);

    expect($job->tries())->toBe(1);
});

test('invoke swarm job timeout property defaults to null', function () {
    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);

    expect($job->timeout)->toBeNull();
});

test('invoke swarm job honors swarm.queue.tries override', function () {
    config()->set('swarm.queue.tries', 3);

    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);

    expect($job->tries())->toBe(3);
});

test('invoke swarm job timeout property reflects swarm.queue.timeout override', function () {
    config()->set('swarm.queue.timeout', 600);

    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);

    expect($job->timeout)->toBe(600);
});

// --- BroadcastSwarm ---

test('broadcast swarm job tries defaults to 1', function () {
    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));

    expect($job->tries())->toBe(1);
});

test('broadcast swarm job timeout property defaults to null', function () {
    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));

    expect($job->timeout)->toBeNull();
});

test('broadcast swarm job honors swarm.queue.tries override', function () {
    config()->set('swarm.queue.tries', 2);

    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));

    expect($job->tries())->toBe(2);
});

test('broadcast swarm job timeout property reflects swarm.queue.timeout override', function () {
    config()->set('swarm.queue.timeout', 300);

    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));

    expect($job->timeout)->toBe(300);
});

// --- ResumeQueuedHierarchicalSwarm ---
// This job is lease-guarded durable coordination — it uses ConfiguresDurableAdvanceJob,
// not ConfiguresQueuedSwarmJob. The tries=1 / token-spend rationale does not apply here.

test('resume queued hierarchical swarm uses the durable advance job trait', function () {
    expect(in_array(ConfiguresDurableAdvanceJob::class, class_uses_recursive(ResumeQueuedHierarchicalSwarm::class), true))->toBeTrue();
});

test('resume queued hierarchical swarm job tries defaults to 3 (durable default)', function () {
    $job = new ResumeQueuedHierarchicalSwarm('run-abc-123');

    expect($job->tries())->toBe(3);
});

test('resume queued hierarchical swarm job honors swarm.durable.job.tries override', function () {
    config()->set('swarm.durable.job.tries', 5);

    $job = new ResumeQueuedHierarchicalSwarm('run-abc-123');

    expect($job->tries())->toBe(5);
});
