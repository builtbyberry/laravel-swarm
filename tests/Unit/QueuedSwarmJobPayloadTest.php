<?php

declare(strict_types=1);

// F4: The test that would have caught the inert-timeout bug.
//
// Strategy: assert the $timeout PROPERTY is set correctly after construction
// (config 600 → $job->timeout === 600; unset → null) AND verify the property
// flows into the serialized queue payload via Laravel's createObjectPayload path.
//
// Laravel's Queue::createObjectPayload() resolves timeout via
// getAttributeValue($job, Timeout::class, 'timeout'), which reads the $timeout
// PROPERTY only — it never calls a timeout() method. We verify the payload by
// using a SyncQueue (which is concrete and calls createPayload internally);
// because createPayload is protected, we proxy through a minimal anonymous
// subclass. This is more robust than Queue::fake(), which stores the job
// instance itself without building a payload.

use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SyncQueue;

// Helper: extract the queue payload for a job using the concrete sync driver.
// createPayload is protected, so we expose it via an anonymous subclass.
function buildQueuePayload(object $job): array
{
    $queue = new class extends SyncQueue
    {
        public function expose(object $job): array
        {
            // createPayloadArray returns the raw array before JSON-encoding;
            // that is the same data createObjectPayload feeds into the payload.
            return $this->createPayloadArray($job, 'default');
        }
    };

    return $queue->expose($job);
}

// --- InvokeSwarm property ---

test('invoke swarm timeout property is null when swarm.queue.timeout is not set', function () {
    config()->set('swarm.queue.timeout', null);

    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);

    expect($job->timeout)->toBeNull();
});

test('invoke swarm timeout property reflects swarm.queue.timeout when set', function () {
    config()->set('swarm.queue.timeout', 600);

    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);

    expect($job->timeout)->toBe(600);
});

// --- BroadcastSwarm property ---

test('broadcast swarm timeout property is null when swarm.queue.timeout is not set', function () {
    config()->set('swarm.queue.timeout', null);

    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));

    expect($job->timeout)->toBeNull();
});

test('broadcast swarm timeout property reflects swarm.queue.timeout when set', function () {
    config()->set('swarm.queue.timeout', 300);

    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));

    expect($job->timeout)->toBe(300);
});

// --- Payload-level: timeout reaches the serialized job payload ---
// Laravel's createObjectPayload reads $timeout via getAttributeValue() (the property path).
// This test proves the value set by applyQueuedSwarmJobTimeout() flows all the way
// into the payload['timeout'] field that the queue worker honours.

test('invoke swarm queue payload carries null timeout when swarm.queue.timeout is unset', function () {
    config()->set('swarm.queue.timeout', null);

    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);
    $payload = buildQueuePayload($job);

    expect($payload['timeout'])->toBeNull();
});

test('invoke swarm queue payload carries configured timeout value', function () {
    config()->set('swarm.queue.timeout', 600);

    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);
    $payload = buildQueuePayload($job);

    expect($payload['timeout'])->toBe(600);
});

test('broadcast swarm queue payload carries null timeout when swarm.queue.timeout is unset', function () {
    config()->set('swarm.queue.timeout', null);

    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));
    $payload = buildQueuePayload($job);

    expect($payload['timeout'])->toBeNull();
});

test('broadcast swarm queue payload carries configured timeout value', function () {
    config()->set('swarm.queue.timeout', 300);

    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));
    $payload = buildQueuePayload($job);

    expect($payload['timeout'])->toBe(300);
});

// --- Payload-level: maxTries reflects swarm.queue.tries ---
// Laravel's getJobTries() checks method_exists($job, 'tries') before falling back to
// the $tries property, so tries() as a method works correctly.

test('invoke swarm queue payload carries maxTries from swarm.queue.tries', function () {
    config()->set('swarm.queue.tries', 1);

    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);
    $payload = buildQueuePayload($job);

    expect($payload['maxTries'])->toBe(1);
});

test('invoke swarm queue payload carries raised maxTries when swarm.queue.tries is overridden', function () {
    config()->set('swarm.queue.tries', 3);

    $job = new InvokeSwarm('App\\Swarms\\MySwarm', []);
    $payload = buildQueuePayload($job);

    expect($payload['maxTries'])->toBe(3);
});

test('broadcast swarm queue payload carries maxTries from swarm.queue.tries', function () {
    config()->set('swarm.queue.tries', 2);

    $job = new BroadcastSwarm('App\\Swarms\\MySwarm', [], new Channel('test'));
    $payload = buildQueuePayload($job);

    expect($payload['maxTries'])->toBe(2);
});
