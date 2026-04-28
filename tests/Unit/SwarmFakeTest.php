<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\EmptyRunnableSwarm;
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
