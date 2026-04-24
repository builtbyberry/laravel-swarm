<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStreamingFailureSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('sequential swarm stream yields ordered payloads and lifecycle events', function () {
    Event::fake();

    $events = iterator_to_array(FakeSequentialSwarm::make()->stream('stream-task'));
    $completedEvent = Event::dispatched(SwarmCompleted::class)->first()[0];
    $history = app(RunHistoryStore::class)->find($completedEvent->runId);

    expect($events)->toBe([
        ['event' => 'step', 'agent' => 'FakeResearcher', 'status' => 'running'],
        ['event' => 'step', 'agent' => 'FakeResearcher', 'status' => 'done'],
        ['event' => 'step', 'agent' => 'FakeWriter', 'status' => 'running'],
        ['event' => 'step', 'agent' => 'FakeWriter', 'status' => 'done'],
        ['event' => 'step', 'agent' => 'FakeEditor', 'status' => 'running'],
        ['event' => 'token', 'token' => 'editor-out'],
        ['event' => 'step', 'agent' => 'FakeEditor', 'status' => 'done'],
    ]);

    Event::assertDispatched(SwarmStarted::class, fn (SwarmStarted $event) => $event->executionMode === 'stream');
    Event::assertDispatchedTimes(SwarmStepStarted::class, 3);
    Event::assertDispatchedTimes(SwarmStepCompleted::class, 3);
    Event::assertDispatched(SwarmCompleted::class, fn (SwarmCompleted $event) => $event->output === 'editor-out');
    expect($completedEvent->metadata)
        ->toHaveKey('swarm_class', FakeSequentialSwarm::class)
        ->toHaveKey('last_agent', FakeEditor::class);
    expect($completedEvent->metadata['usage'])->toBeArray();
    expect($completedEvent->metadata['usage'])->not->toBe([]);
    expect($history['usage'])->toBe($completedEvent->metadata['usage']);
});

test('sequential swarm stream accepts structured task input', function () {
    $events = iterator_to_array(FakeSequentialSwarm::make()->stream([
        'ticket_id' => 'TKT-1234',
        'customer_tier' => 'enterprise',
        'issue' => 'Need help with a billing mismatch.',
    ]));

    expect($events)->toContain(['event' => 'token', 'token' => 'editor-out']);
});

test('sequential swarm stream accepts explicit run contexts', function () {
    $events = iterator_to_array(FakeSequentialSwarm::make()->stream(RunContext::from([
        'input' => 'Draft a response for the customer.',
        'data' => ['ticket_id' => 'TKT-1234'],
        'metadata' => ['tenant_id' => 'acme'],
    ], 'stream-run-id')));

    expect($events)->toContain(['event' => 'token', 'token' => 'editor-out']);
});

test('sequential swarm stream marks history failed and dispatches failure when the final agent throws mid stream', function () {
    Event::fake();

    $stream = FakeStreamingFailureSwarm::make()->stream('stream-task');
    $received = [];

    try {
        foreach ($stream as $event) {
            $received[] = $event;
        }

        $this->fail('Expected the streamed swarm to throw.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Final agent stream failed.');
    }

    expect($received)->toBe([
        ['event' => 'step', 'agent' => 'FakeResearcher', 'status' => 'running'],
        ['event' => 'step', 'agent' => 'FakeResearcher', 'status' => 'done'],
        ['event' => 'step', 'agent' => 'FakeWriter', 'status' => 'running'],
        ['event' => 'step', 'agent' => 'FakeWriter', 'status' => 'done'],
        ['event' => 'step', 'agent' => 'FailingStreamEditor', 'status' => 'running'],
        ['event' => 'token', 'token' => 'partial'],
    ]);

    Event::assertDispatchedTimes(SwarmStepStarted::class, 3);
    Event::assertDispatchedTimes(SwarmStepCompleted::class, 2);
    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $event) => $event->exception->getMessage() === 'Final agent stream failed.');
    Event::assertNotDispatched(SwarmCompleted::class);

    $failedEvent = Event::dispatched(SwarmFailed::class)->first()[0];
    $history = app(RunHistoryStore::class)->find($failedEvent->runId);

    expect($history['status'])->toBe('failed');
    expect($history['error'])->toBe([
        'message' => 'Final agent stream failed.',
        'class' => RuntimeException::class,
    ]);
    expect($history['steps'])->toHaveCount(2);
});

test('non sequential swarms cannot be streamed', function () {
    $stream = fn () => iterator_to_array(FakeParallelSwarm::make()->stream('stream-task'));

    expect($stream)->toThrow(
        SwarmException::class,
        'Streaming is only supported for sequential swarms. parallel topology does not support streaming.',
    );
});
