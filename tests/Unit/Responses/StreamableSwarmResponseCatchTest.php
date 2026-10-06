<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;

/**
 * Stream catch() is in-process (best-effort by nature) and symmetric to then():
 * it fires only on a FAILED terminal, is a handler (never a suppressor), and never
 * fires on completion or on an abandoned (early-break) stream.
 */
function catchStreamThatFails(Throwable $error): StreamableSwarmResponse
{
    return new StreamableSwarmResponse('run-catch', function () use ($error): Generator {
        yield new SwarmStreamStart('start', 'run-catch', 'App\\Swarms\\S', 'sequential', 'in', [], 1);

        throw $error;
    });
}

function catchStreamThatCompletes(): StreamableSwarmResponse
{
    return new StreamableSwarmResponse('run-done', function (): Generator {
        yield new SwarmStreamEnd('end', 'run-done', 'out', [], [], 2);
    });
}

it('runs catch on a failed stream and still propagates the original exception', function (): void {
    $error = new RuntimeException('boom');
    $seen = null;

    $stream = catchStreamThatFails($error)->catch(function (Throwable $e) use (&$seen): void {
        $seen = $e;
    });

    expect(function () use ($stream): void {
        foreach ($stream as $event) {
            // drain
        }
    })->toThrow(RuntimeException::class, 'boom');

    expect($seen)->toBe($error);
});

it('does not run catch when the stream completes, and does run then', function (): void {
    $caught = false;
    $thened = false;

    $stream = catchStreamThatCompletes()
        ->then(function () use (&$thened): void {
            $thened = true;
        })
        ->catch(function () use (&$caught): void {
            $caught = true;
        });

    foreach ($stream as $event) {
        // drain to completion
    }

    expect($thened)->toBeTrue();
    expect($caught)->toBeFalse();
});

it('does not run catch when the stream is abandoned by an early break', function (): void {
    $caught = false;

    $stream = new StreamableSwarmResponse('run-abandon', function (): Generator {
        yield new SwarmStreamStart('start', 'run-abandon', 'App\\Swarms\\S', 'sequential', 'in', [], 1);
        yield new SwarmStreamStart('start-2', 'run-abandon', 'App\\Swarms\\S', 'sequential', 'in', [], 2);
    });

    $stream->catch(function () use (&$caught): void {
        $caught = true;
    });

    foreach ($stream as $event) {
        break; // abandon before completion
    }

    expect($caught)->toBeFalse();
});

it('invokes a catch registered after the stream already failed, immediately', function (): void {
    $error = new RuntimeException('late');
    $stream = catchStreamThatFails($error);

    try {
        foreach ($stream as $event) {
        }
    } catch (RuntimeException) {
        // expected
    }

    $first = null;
    $second = null;
    $stream->catch(function (Throwable $e) use (&$first): void {
        $first = $e;
    });
    // A second late catch also fires — there is no run-once latch.
    $stream->catch(function (Throwable $e) use (&$second): void {
        $second = $e;
    });

    expect($first)->toBe($error);
    expect($second)->toBe($error);
});

it('swallows a throwing catch callback so it cannot mask the workflow error', function (): void {
    $stream = catchStreamThatFails(new RuntimeException('workflow'))
        ->catch(function (): void {
            throw new LogicException('callback blew up');
        });

    // The ORIGINAL workflow exception must surface, not the callback's.
    expect(function () use ($stream): void {
        foreach ($stream as $event) {
        }
    })->toThrow(RuntimeException::class, 'workflow');
});

it('does not re-run catch when a failed stream is iterated again', function (): void {
    $count = 0;
    $stream = catchStreamThatFails(new RuntimeException('once'))
        ->catch(function () use (&$count): void {
            $count++;
        });

    foreach ([1, 2] as $_) {
        try {
            foreach ($stream as $event) {
            }
        } catch (RuntimeException) {
            // expected each pass
        }
    }

    expect($count)->toBe(1);
});
