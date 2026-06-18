<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use Illuminate\Broadcasting\Channel;

beforeEach(function () {
    config()->set('swarm.queue.tries', 1);
    config()->set('swarm.queue.timeout', null);
});

// --- InvokeSwarm ---

test('invoke swarm job tries defaults to 1', function () {
    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);

    expect($job->tries())->toBe(1);
});

test('invoke swarm job timeout defaults to null', function () {
    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);

    expect($job->timeout())->toBeNull();
});

test('invoke swarm job honors swarm.queue.tries override', function () {
    config()->set('swarm.queue.tries', 3);

    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);

    expect($job->tries())->toBe(3);
});

test('invoke swarm job honors swarm.queue.timeout override', function () {
    config()->set('swarm.queue.timeout', 600);

    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);

    expect($job->timeout())->toBe(600);
});

// --- BroadcastSwarm ---

test('broadcast swarm job tries defaults to 1', function () {
    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));

    expect($job->tries())->toBe(1);
});

test('broadcast swarm job timeout defaults to null', function () {
    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));

    expect($job->timeout())->toBeNull();
});

test('broadcast swarm job honors swarm.queue.tries override', function () {
    config()->set('swarm.queue.tries', 2);

    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));

    expect($job->tries())->toBe(2);
});

test('broadcast swarm job honors swarm.queue.timeout override', function () {
    config()->set('swarm.queue.timeout', 300);

    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));

    expect($job->timeout())->toBe(300);
});

// --- ResumeQueuedHierarchicalSwarm ---

test('resume queued hierarchical swarm job tries defaults to 1', function () {
    $job = new ResumeQueuedHierarchicalSwarm('run-abc-123');

    expect($job->tries())->toBe(1);
});

test('resume queued hierarchical swarm job timeout defaults to null', function () {
    $job = new ResumeQueuedHierarchicalSwarm('run-abc-123');

    expect($job->timeout())->toBeNull();
});

test('resume queued hierarchical swarm job honors swarm.queue.tries override', function () {
    config()->set('swarm.queue.tries', 5);

    $job = new ResumeQueuedHierarchicalSwarm('run-abc-123');

    expect($job->tries())->toBe(5);
});

test('resume queued hierarchical swarm job honors swarm.queue.timeout override', function () {
    config()->set('swarm.queue.timeout', 120);

    $job = new ResumeQueuedHierarchicalSwarm('run-abc-123');

    expect($job->timeout())->toBe(120);
});
