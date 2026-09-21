<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Runners\ConcurrentAgentResult;
use BuiltByBerry\LaravelSwarm\Runners\NativeOutcomeValidator;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\ContextFailure;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\WakesWithFailure;
use Illuminate\Concurrency\SyncDriver;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;

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

it('keeps binary success bytes intact through the outer JSON process envelope', function () {
    $result = ConcurrentAgentResult::capture(fn () => ['output' => "output-\xFF"]);
    $wire = json_encode(['successful' => true, 'result' => serialize($result)], JSON_THROW_ON_ERROR);
    $decoded = json_decode($wire, true, flags: JSON_THROW_ON_ERROR);
    expect(unserialize($decoded['result'])->value())->toBe(['output' => "output-\xFF"]);
});

it('converts an untransportable success value into a reported ordinary failure', function () {
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->once()->with(Mockery::type(Exception::class));
    app()->instance(ExceptionHandler::class, $handler);
    $result = ConcurrentAgentResult::capture(fn () => ['tool_result' => fn () => null]);
    $wire = json_encode(['successful' => true, 'result' => serialize($result)], JSON_THROW_ON_ERROR);
    $decoded = json_decode($wire, true, flags: JSON_THROW_ON_ERROR);
    expect(fn () => unserialize($decoded['result'])->value())->toThrow(Exception::class, 'Serialization of');
});

it('retains permanent native classification even if its diagnostic text cannot be transported', function (string $class) {
    $result = ConcurrentAgentResult::capture(fn () => throw new $class("invalid-\xFF"));
    $wire = json_encode(['result' => serialize($result)], JSON_THROW_ON_ERROR);
    expect(fn () => unserialize(json_decode($wire, true)['result'])->value())->toThrow($class);
})->with([UnsupportedNativeApprovalException::class, ApprovalNotResumableException::class]);

it('inspects sibling native failures before waking returned tool-result objects', function () {
    $success = ConcurrentAgentResult::capture(fn () => ['tool_result' => new WakesWithFailure]);
    $failure = ConcurrentAgentResult::capture(fn () => throw new UnsupportedNativeApprovalException);
    $results = [unserialize(serialize($success)), unserialize(serialize($failure))];
    expect(fn () => app(NativeOutcomeValidator::class)->validateConcurrentResults($results))->toThrow(UnsupportedNativeApprovalException::class);
    expect(fn () => $results[0]->value())->toThrow(RuntimeException::class, 'tool-result-wakeup');
});
