<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\ConcurrentAgentResult;
use BuiltByBerry\LaravelSwarm\Runners\NativeOutcomeValidator;
use Illuminate\Concurrency\SyncDriver;
use Illuminate\Contracts\Concurrency\Driver;

it('preserves sync short circuit and the original ordinary exception', function () {
    $error = new RuntimeException('ordinary-first');
    $laterCalls = 0;
    expect(fn () => app(NativeOutcomeValidator::class)->runConcurrent(new SyncDriver, [
        fn () => throw $error,
        function () use (&$laterCalls) {
            $laterCalls++;

            return [];
        },
    ]))->toThrow(fn (RuntimeException $caught) => expect($caught)->toBe($error));
    expect($laterCalls)->toBe(0);
});

it('retains custom concurrency driver semantics without wrapping its callbacks', function () {
    $callbacks = [fn () => ['output' => 'supported']];
    $driver = Mockery::mock(Driver::class);
    $driver->shouldReceive('run')->with($callbacks)->once()->andReturn([['output' => 'supported']]);
    expect(app(NativeOutcomeValidator::class)->runConcurrent($driver, $callbacks))->toBe([['output' => 'supported']]);
});

it('transports ordinary exception constructor data without a captured exception trace', function () {
    $callback = function () {
        throw new RuntimeException('ordinary-first');
    };
    $result = ConcurrentAgentResult::capture($callback);
    $wire = serialize($result);
    expect($wire)->not->toContain('trace', 'Closure');
    expect(fn () => unserialize($wire)->value())->toThrow(RuntimeException::class, 'ordinary-first');
});
