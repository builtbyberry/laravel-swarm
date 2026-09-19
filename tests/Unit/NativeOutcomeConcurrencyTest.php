<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Runners\ConcurrentAgentResult;
use BuiltByBerry\LaravelSwarm\Runners\NativeOutcomeValidator;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\ContextFailure;
use Illuminate\Concurrency\SyncDriver;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Contracts\Debug\ExceptionHandler;

function inspectConcurrentTestResults(Driver $driver, array $callbacks): array
{
    return app(NativeOutcomeValidator::class)->validateConcurrentResults($driver->run(ConcurrentAgentResult::wrapCallbacks($driver, $callbacks)));
}

it('preserves sync short circuit and the original ordinary exception', function () {
    $error = new RuntimeException('ordinary-first');
    $laterCalls = 0;
    expect(fn () => inspectConcurrentTestResults(new SyncDriver, [
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
    expect(inspectConcurrentTestResults($driver, $callbacks))->toBe([['output' => 'supported']]);
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

it('keeps captured objects out of exception transport', function () {
    $error = new ContextFailure(new RuntimeException('nested-secret'));
    $wire = serialize(ConcurrentAgentResult::capture(fn () => throw $error));
    expect($wire)->not->toContain('nested-secret', 'trace');
    expect(fn () => unserialize($wire)->value())->toThrow(ContextFailure::class);
});

it('preserves the original outcome when worker reporting fails', function () {
    $error = new UnsupportedNativeApprovalException;
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->with($error)->once()->andThrow(new RuntimeException('reporting unavailable'));
    app()->instance(ExceptionHandler::class, $handler);
    $result = ConcurrentAgentResult::capture(fn () => throw $error);
    expect(fn () => $result->value())->toThrow(fn (UnsupportedNativeApprovalException $caught) => expect($caught)->toBe($error));
});

it('reports an untransportable ordinary error safely if no native outcome takes precedence', function () {
    $resource = fopen('php://temp', 'r+');
    try {
        $error = new ContextFailure($resource);
        $wire = serialize(ConcurrentAgentResult::capture(fn () => throw $error));
        expect(fn () => unserialize($wire)->value())->toThrow(SwarmException::class, 'Concurrent agent failure could not be transported');
    } finally {
        fclose($resource);
    }
});

it('preserves valid ordinary constructor data across process transport', function () {
    $error = new ContextFailure(['reason' => 'ordinary', 'code' => 42]);
    $wire = serialize(ConcurrentAgentResult::capture(fn () => throw $error));
    expect(fn () => unserialize($wire)->value())->toThrow(fn (ContextFailure $caught) => expect($caught->context)->toBe(['reason' => 'ordinary', 'code' => 42]));
});
