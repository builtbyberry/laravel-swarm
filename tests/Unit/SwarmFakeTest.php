<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\EmptyRunnableSwarm;
use PHPUnit\Framework\AssertionFailedError;

test('fake intercepts run and queue calls', function () {
    EmptyRunnableSwarm::fake();

    $swarm = EmptyRunnableSwarm::make();
    expect($swarm)->toBeInstanceOf(SwarmFake::class);

    $swarm->run('alpha');
    $swarm->queue('beta');

    EmptyRunnableSwarm::assertRan('alpha');
    EmptyRunnableSwarm::assertQueued('beta');
});

test('fake intercepts stream calls', function () {
    EmptyRunnableSwarm::fake(['streamed-output']);

    $events = iterator_to_array(EmptyRunnableSwarm::make()->stream('stream-task'));

    expect($events)->toBe([
        ['event' => 'token', 'token' => 'streamed-output'],
    ]);

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

test('assert never ran passes when idle', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::assertNeverRan();
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

test('assert never queued passes when idle', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::assertNeverQueued();
});

test('assert never queued fails after a queue call', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::make()->queue('x');

    expect(fn () => EmptyRunnableSwarm::assertNeverQueued())->toThrow(AssertionFailedError::class);
});

test('assert never streamed passes when idle', function () {
    EmptyRunnableSwarm::fake();

    EmptyRunnableSwarm::assertNeverStreamed();
});

test('assert never streamed fails after a stream call', function () {
    EmptyRunnableSwarm::fake();

    iterator_to_array(EmptyRunnableSwarm::make()->stream('x'));

    expect(fn () => EmptyRunnableSwarm::assertNeverStreamed())->toThrow(AssertionFailedError::class);
});
