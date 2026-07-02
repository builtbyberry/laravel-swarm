<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ContainerResolvedQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\EmptyRunnableSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalSingleWorkerSwarm;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;

test('fake intercepts run and queue calls', function () {
    EmptyRunnableSwarm::fake();

    $swarm = EmptyRunnableSwarm::make();
    expect($swarm)->toBeInstanceOf(SwarmFake::class);

    $swarm->run('alpha');
    $swarm->queue('beta');
    $swarm->dispatchDurable('gamma');

    EmptyRunnableSwarm::assertRan('alpha');
    EmptyRunnableSwarm::assertQueued('beta');
    EmptyRunnableSwarm::assertDispatchedDurably('gamma');
});

test('make without arguments resolves the swarm through the container', function () {
    $resolved = new EmptyRunnableSwarm;

    app()->instance(EmptyRunnableSwarm::class, $resolved);

    expect(EmptyRunnableSwarm::make())->toBe($resolved);
});

test('make with named arguments resolves the swarm through container make with', function () {
    $swarm = ContainerResolvedQueuedSwarm::make(output: 'named-output');

    expect((string) $swarm->run('task'))->toBe('named-output');
});

test('make with positional arguments creates a direct runtime instance', function () {
    $swarm = ContainerResolvedQueuedSwarm::make('positional-output');

    expect((string) $swarm->run('task'))->toBe('positional-output');
});

test('fake intercepts prompt and run calls in the same sync bucket', function () {
    EmptyRunnableSwarm::fake(['prompt-output', 'run-output']);

    $swarm = EmptyRunnableSwarm::make();

    expect((string) $swarm->prompt('alpha'))->toBe('prompt-output');
    expect((string) $swarm->run('beta'))->toBe('run-output');

    EmptyRunnableSwarm::assertPrompted('alpha');
    EmptyRunnableSwarm::assertPrompted('beta');
    EmptyRunnableSwarm::assertRan('alpha');
    EmptyRunnableSwarm::assertRan('beta');
});

test('fake make returns the fake even when positional arguments are passed', function () {
    $fake = EmptyRunnableSwarm::fake();

    expect(EmptyRunnableSwarm::make('runtime-state'))->toBe($fake);
});

test('fake assertion helpers resolve the fake through the container', function () {
    $fake = new SwarmFake(EmptyRunnableSwarm::class);

    app()->instance(EmptyRunnableSwarm::class, $fake);

    $fake->run('container-task');
    $fake->queue('container-queue-task');

    EmptyRunnableSwarm::assertRan('container-task');
    EmptyRunnableSwarm::assertQueued('container-queue-task');
});

test('fake intercepts positional make run calls', function () {
    EmptyRunnableSwarm::fake(['positional-output']);

    expect((string) EmptyRunnableSwarm::make('runtime-state')->run('alpha'))->toBe('positional-output');

    EmptyRunnableSwarm::assertRan('alpha');
});

test('fake intercepts stream calls', function () {
    EmptyRunnableSwarm::fake(['streamed-output']);

    $events = iterator_to_array(EmptyRunnableSwarm::make()->stream('stream-task'));

    expect(array_map(fn ($event): string => $event->type(), $events))->toBe([
        'swarm_stream_start',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_step_end',
        'swarm_stream_end',
    ]);
    expect($events[2])->toBeInstanceOf(SwarmTextDelta::class);
    expect($events[2]->delta)->toBe('streamed-output');

    EmptyRunnableSwarm::assertStreamed('stream-task');
});

test('fake stream emits topology from the swarm class attribute', function () {
    FakeStaticHierarchicalSingleWorkerSwarm::fake(['topology-test-output']);

    $events = iterator_to_array(FakeStaticHierarchicalSingleWorkerSwarm::make()->stream('topology-task'));

    $start = $events[0];

    expect($start)->toBeInstanceOf(SwarmStreamStart::class);
    expect($start->topology)->toBe('static_hierarchical');
});

test('fake intercepts broadcast calls as stream calls', function () {
    Event::fake([AnonymousEvent::class]);
    EmptyRunnableSwarm::fake(['broadcast-output']);

    $response = EmptyRunnableSwarm::make()->broadcast('broadcast-task', new Channel('swarm.fake'));

    expect($response)->toBeInstanceOf(StreamableSwarmResponse::class);
    expect($response->streamedResponse?->output)->toBe('broadcast-output');

    EmptyRunnableSwarm::assertStreamed('broadcast-task');
    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event) => $event->broadcastAs() === 'swarm_stream_end');
});

test('fake intercepts broadcast now calls as stream calls', function () {
    Event::fake([AnonymousEvent::class]);
    EmptyRunnableSwarm::fake(['broadcast-now-output']);

    EmptyRunnableSwarm::make()->broadcastNow('broadcast-now-task', new Channel('swarm.fake'));

    EmptyRunnableSwarm::assertStreamed('broadcast-now-task');
    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event) => $event->shouldBroadcastNow());
});

test('fake intercepts broadcast on queue calls as queue calls', function () {
    EmptyRunnableSwarm::fake();

    $response = EmptyRunnableSwarm::make()->broadcastOnQueue('queued-broadcast-task', new Channel('swarm.fake'));

    expect($response)->toBeInstanceOf(QueuedSwarmResponse::class);

    EmptyRunnableSwarm::assertQueued('queued-broadcast-task');
});

test('array responses are consumed in order', function () {
    EmptyRunnableSwarm::fake(['first', 'second']);

    expect((string) EmptyRunnableSwarm::make()->run('a'))->toBe('first');
    expect((string) EmptyRunnableSwarm::make()->run('b'))->toBe('second');
});

test('callable responses receive the task string', function () {
    EmptyRunnableSwarm::fake(fn (string $task): string => 'echo:'.$task);

    expect((string) EmptyRunnableSwarm::make()->run('hello'))->toBe('echo:hello');
});

test('callable responses receive structured task values', function () {
    EmptyRunnableSwarm::fake(fn (array $task): string => 'ticket:'.$task['ticket_id']);

    expect((string) EmptyRunnableSwarm::make()->run([
        'ticket_id' => 'TKT-1234',
        'customer_tier' => 'enterprise',
    ]))->toBe('ticket:TKT-1234');
});

test('assert ran passes for matching tasks', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->run('expected');

    EmptyRunnableSwarm::assertRan('expected');
});

test('assert ran fails for missing tasks', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->run('one');

    expect(fn () => EmptyRunnableSwarm::assertRan('two'))->toThrow(AssertionFailedError::class);
});

test('assert ran supports array subset matching', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->run([
        'ticket_id' => 'TKT-1234',
        'customer_tier' => 'enterprise',
        'issue' => 'Need help with a billing mismatch.',
    ]);

    EmptyRunnableSwarm::assertRan(['ticket_id' => 'TKT-1234']);
});

test('assert ran supports run context values', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->run(RunContext::from([
        'input' => 'Draft a response for the customer.',
        'data' => ['ticket_id' => 'TKT-1234', 'customer_tier' => 'enterprise'],
        'metadata' => ['tenant_id' => 'acme'],
    ]));

    EmptyRunnableSwarm::assertRan(['ticket_id' => 'TKT-1234']);
});

test('assert never ran passes when idle', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::assertNeverRan();
});

test('assert never prompted passes when idle', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::assertNeverPrompted();
});

test('assert never prompted fails after a prompt', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->prompt('nope');

    expect(fn () => EmptyRunnableSwarm::assertNeverPrompted())->toThrow(AssertionFailedError::class);
});

test('assert never ran fails after a run', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->run('nope');

    expect(fn () => EmptyRunnableSwarm::assertNeverRan())->toThrow(AssertionFailedError::class);
});

test('assert queued passes for matching tasks', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->queue('queued-task');

    EmptyRunnableSwarm::assertQueued('queued-task');
});

test('assert queued supports array subset matching', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->queue([
        'ticket_id' => 'TKT-1234',
        'customer_tier' => 'enterprise',
        'issue' => 'Need help with a billing mismatch.',
    ]);

    EmptyRunnableSwarm::assertQueued(['ticket_id' => 'TKT-1234']);
});

test('assert never queued passes when idle', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::assertNeverQueued();
});

test('assert never queued fails after a queue call', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->queue('x');

    expect(fn () => EmptyRunnableSwarm::assertNeverQueued())->toThrow(AssertionFailedError::class);
});

test('assert durable dispatched supports array subset matching', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->dispatchDurable([
        'ticket_id' => 'TKT-1234',
        'customer_tier' => 'enterprise',
        'issue' => 'Need help with a billing mismatch.',
    ]);

    EmptyRunnableSwarm::assertDispatchedDurably(['ticket_id' => 'TKT-1234']);
});

test('assert never durably dispatched fails after a durable dispatch', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->dispatchDurable('x');

    expect(fn () => EmptyRunnableSwarm::assertNeverDispatchedDurably())->toThrow(AssertionFailedError::class);
});

test('fake durable response records operations without the database manager', function () {
    EmptyRunnableSwarm::fake();

    $response = EmptyRunnableSwarm::make()->dispatchDurable('durable-task');

    expect($response->signal('approval_received', ['approved' => true], 'signal-1')->accepted)->toBeTrue()
        ->and($response->pause()->status)->toBe('paused')
        ->and($response->resume()->status)->toBe('resumed')
        ->and($response->cancel()->status)->toBe('cancelled')
        ->and($response->inspect()->run['status'])->toBe('fake');

    EmptyRunnableSwarm::assertDurableSignalled('approval_received');
});

test('fake durable assertions cover durable runtime surfaces', function () {
    $fake = EmptyRunnableSwarm::fake();

    $fake
        ->recordDurableWait('approval_received', 'Waiting for approval')
        ->recordDurableProgress(['stage' => 'tool-call'])
        ->recordDurableLabels(['tenant' => 'acme'])
        ->recordDurableDetails(['ticket_id' => 'TKT-1234'])
        ->recordDurableRetry(['max_attempts' => 3])
        ->recordDurableChildSwarm(EmptyRunnableSwarm::class, 'child-task');

    EmptyRunnableSwarm::assertDurableWaited('approval_received');
    EmptyRunnableSwarm::assertDurableProgressRecorded(['stage' => 'tool-call']);
    EmptyRunnableSwarm::assertDurableLabels(['tenant' => 'acme']);
    EmptyRunnableSwarm::assertDurableDetails(['ticket_id' => 'TKT-1234']);
    EmptyRunnableSwarm::assertDurableRetryScheduled(['max_attempts' => 3]);
    EmptyRunnableSwarm::assertDurableChildSwarmDispatched(EmptyRunnableSwarm::class);

    expect(fn () => EmptyRunnableSwarm::assertDurableWaited('missing-wait'))->toThrow(AssertionFailedError::class);
});

test('assert never streamed passes when idle', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::assertNeverStreamed();
});

test('assert streamed supports array subset matching', function () {
    EmptyRunnableSwarm::fake();

    iterator_to_array(EmptyRunnableSwarm::make()->stream([
        'ticket_id' => 'TKT-1234',
        'customer_tier' => 'enterprise',
        'issue' => 'Need help with a billing mismatch.',
    ]));

    EmptyRunnableSwarm::assertStreamed(['ticket_id' => 'TKT-1234']);
});

test('assert never streamed fails after a stream call', function () {
    EmptyRunnableSwarm::fake();

    iterator_to_array(EmptyRunnableSwarm::make()->stream('x'));

    expect(fn () => EmptyRunnableSwarm::assertNeverStreamed())->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// Actor assertion helpers (#34, v0.4.3)
// ---------------------------------------------------------------------------

test('assertDispatchedWithActor matches an Actor instance bound via withActor', function () {
    EmptyRunnableSwarm::fake();

    $context = RunContext::fromTask('task')->withActor(
        new Actor(id: 'u-42', type: 'user'),
    );

    EmptyRunnableSwarm::make()->run($context);

    EmptyRunnableSwarm::assertDispatchedWithActor(
        new Actor(id: 'u-42', type: 'user'),
    );
});

test('assertDispatchedWithActor matches a "type:id" string shorthand', function () {
    EmptyRunnableSwarm::fake();

    $context = RunContext::fromTask('task')->withActor('api_token:abc-123');

    EmptyRunnableSwarm::make()->queue($context);

    EmptyRunnableSwarm::assertDispatchedWithActor('api_token:abc-123');
});

test('assertDispatchedWithActor accepts a callable predicate', function () {
    EmptyRunnableSwarm::fake();

    $context = RunContext::fromTask('task')->withActor(
        new Actor(id: 'cron:nightly', type: 'system'),
    );

    EmptyRunnableSwarm::make()->dispatchDurable($context);

    EmptyRunnableSwarm::assertDispatchedWithActor(
        fn (Actor $actor): bool => $actor->type === 'system',
    );
});

test('assertDispatchedWithActor fails when no actor was bound', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->run('bare-string-task');

    expect(fn () => EmptyRunnableSwarm::assertDispatchedWithActor('user:42'))
        ->toThrow(AssertionFailedError::class);
});

test('assertDispatchedWithActor inspects every dispatch bucket', function () {
    EmptyRunnableSwarm::fake();

    $promptCtx = RunContext::fromTask('p')->withActor('user:1');
    $queueCtx = RunContext::fromTask('q')->withActor('user:2');
    $durableCtx = RunContext::fromTask('d')->withActor('user:3');

    $fake = EmptyRunnableSwarm::make();
    $fake->run($promptCtx);
    $fake->queue($queueCtx);
    $fake->dispatchDurable($durableCtx);

    EmptyRunnableSwarm::assertDispatchedWithActor('user:1');
    EmptyRunnableSwarm::assertDispatchedWithActor('user:2');
    EmptyRunnableSwarm::assertDispatchedWithActor('user:3');
});

test('assertDispatchedWithAnyActor passes when at least one dispatch carried an actor', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->run('bare-task');
    EmptyRunnableSwarm::make()->run(RunContext::fromTask('with-actor')->withActor('system:cron'));

    EmptyRunnableSwarm::assertDispatchedWithAnyActor();
});

test('assertDispatchedWithAnyActor fails when no dispatch carried an actor', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->run('bare-task');
    EmptyRunnableSwarm::make()->queue('also-bare');

    expect(fn () => EmptyRunnableSwarm::assertDispatchedWithAnyActor())
        ->toThrow(AssertionFailedError::class);
});

test('assertNeverDispatchedWithActor passes when no dispatch carried an actor', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->run('bare-task');
    EmptyRunnableSwarm::make()->queue('also-bare');

    EmptyRunnableSwarm::assertNeverDispatchedWithActor();
});

test('assertNeverDispatchedWithActor fails when a dispatch carried an actor', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->run(RunContext::fromTask('task')->withActor('user:42'));

    expect(fn () => EmptyRunnableSwarm::assertNeverDispatchedWithActor())
        ->toThrow(AssertionFailedError::class);
});

test('actor assertions ignore bare-string and structured-array tasks', function () {
    EmptyRunnableSwarm::fake();

    // Bare strings and plain structured arrays carry no actor — even if a
    // metadata.actor entry is set on a structured task array, SwarmFake only
    // inspects RunContext instances per the v0.4.3 design.
    EmptyRunnableSwarm::make()->run(['input' => 'x', 'metadata' => ['actor' => ['id' => 'u-1', 'type' => 'user']]]);

    expect(fn () => EmptyRunnableSwarm::assertDispatchedWithActor('user:u-1'))
        ->toThrow(AssertionFailedError::class);
});
