<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Streaming\Parallel\ParallelStreamSession;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support\DeserializationProbe;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\FakeProcessDescription;

/**
 * Drive a session straight to its process-result validation: with no declared
 * branches the event loop has nothing to wait for, so only the envelope is read.
 */
function validateParallelStreamEnvelope(string $serializedResult, ?string $key = null): void
{
    $payload = json_encode(['successful' => true, 'result' => $serializedResult], JSON_THROW_ON_ERROR);
    validateParallelStreamOutput($key === null ? $payload : sessionSignedProcessResult($payload, $key), $key === null ? null : ['parallel:0' => $key]);
}

function sessionSignedProcessResult(string $payload, string $key): string
{
    $encoded = base64_encode($payload);

    return "\n@@LARAVEL-CONCURRENCY-V1@@{$encoded}@@".hash_hmac('sha256', $encoded, $key)."@@\n";
}

/** @param array<string, string>|null $keys */
function validateParallelStreamOutput(string $output, ?array $keys): void
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    expect($server)->toBeResource();

    $process = new FakeInvokedProcess('parallel-branch', (new FakeProcessDescription)
        ->output($output)
        ->exitCode(0));

    $session = new ParallelStreamSession(
        server: $server,
        processes: ['parallel:0' => $process],
        branchIds: [],
        token: 'token',
        maxFrameBytes: 1_024,
        deadline: (float) hrtime(true) + 2_000_000_000,
        cancelGraceMilliseconds: 10,
        resultKeys: $keys,
    );

    iterator_to_array($session->events(), false);
}

beforeEach(function (): void {
    DeserializationProbe::reset();
});

test('a branch process result envelope is accepted when it is the class-free terminal array', function (): void {
    validateParallelStreamEnvelope(serialize(['branch_id' => 'parallel:0', 'terminal_sent' => true]));

    expect(DeserializationProbe::$woken)->toBe(0);
});

test('a branch process result envelope never constructs a top-level object', function (): void {
    expect(fn () => validateParallelStreamEnvelope(DeserializationProbe::wire()))
        ->toThrow(SwarmException::class, 'returned an invalid process result envelope');

    expect(DeserializationProbe::$woken)->toBe(0);
});

test('a branch process result envelope never constructs an object smuggled inside a valid array', function (): void {
    $wire = 'a:3:{s:9:"branch_id";s:10:"parallel:0";s:13:"terminal_sent";b:1;s:5:"extra";'.DeserializationProbe::wire().'}';

    validateParallelStreamEnvelope($wire);

    expect(DeserializationProbe::$woken)->toBe(0);
});

test('signed branch process results retain class-free deserialization', function (): void {
    validateParallelStreamEnvelope(serialize(['branch_id' => 'parallel:0', 'terminal_sent' => true]), 'secret');
    expect(fn () => validateParallelStreamEnvelope(DeserializationProbe::wire(), 'secret'))
        ->toThrow(SwarmException::class, 'returned an invalid process result envelope');
    validateParallelStreamEnvelope('a:3:{s:9:"branch_id";s:10:"parallel:0";s:13:"terminal_sent";b:1;s:5:"extra";'.DeserializationProbe::wire().'}', 'secret');

    expect(DeserializationProbe::$woken)->toBe(0);
});

test('signed branch process results reject missing keys and plain JSON downgrades', function (): void {
    $payload = json_encode(['successful' => true, 'result' => serialize(['branch_id' => 'parallel:0', 'terminal_sent' => true])]);
    expect(fn () => validateParallelStreamOutput($payload, []))
        ->toThrow(SwarmException::class, 'returned an invalid process result envelope');
    expect(fn () => validateParallelStreamOutput($payload, ['parallel:0' => '']))
        ->toThrow(SwarmException::class, 'returned an invalid process result envelope');
    expect(fn () => validateParallelStreamOutput($payload, ['parallel:0' => 'secret']))
        ->toThrow(SwarmException::class, 'returned an invalid process result envelope');
});

test('malformed branch results are redacted and restore the error handler', function (mixed $result): void {
    $payload = json_encode(['successful' => true, 'result' => $result], JSON_THROW_ON_ERROR);
    $handler = static fn (): bool => false;
    set_error_handler($handler);

    try {
        expect(fn () => validateParallelStreamOutput(sessionSignedProcessResult($payload, 'secret'), ['parallel:0' => 'secret']))
            ->toThrow(SwarmException::class, 'returned an invalid process result envelope');
        $current = set_error_handler($handler);
        restore_error_handler();
        expect($current)->toBe($handler);
    } finally {
        restore_error_handler();
    }
})->with([
    'array result' => [[]],
    'invalid serialized value' => ['SENSITIVE not serialized'],
    'trailing serialized bytes' => [serialize(['branch_id' => 'parallel:0', 'terminal_sent' => true]).'SENSITIVE'],
    'wrong branch' => [serialize(['branch_id' => 'other', 'terminal_sent' => true])],
    'terminal marker absent' => [serialize(['branch_id' => 'parallel:0'])],
]);

test('authenticated unsuccessful results never instantiate the reported exception or expose its data', function (): void {
    $payload = json_encode(['successful' => false, 'result' => serialize(['branch_id' => 'parallel:0', 'terminal_sent' => true]), 'exception' => DeserializationProbe::class, 'message' => 'SENSITIVE', 'parameters' => ['secret']], JSON_THROW_ON_ERROR);

    try {
        validateParallelStreamOutput(sessionSignedProcessResult($payload, 'secret'), ['parallel:0' => 'secret']);
        $this->fail('Expected a rejected process result.');
    } catch (SwarmException $exception) {
        expect($exception->getMessage())->toBe('Parallel stream branch [parallel:0] returned an invalid process result envelope.');
    }

    expect(DeserializationProbe::$woken)->toBe(0);
});
