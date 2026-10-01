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
function validateParallelStreamEnvelope(string $serializedResult): void
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    expect($server)->toBeResource();

    $process = new FakeInvokedProcess('parallel-branch', (new FakeProcessDescription)
        ->output(json_encode(['successful' => true, 'result' => $serializedResult], JSON_THROW_ON_ERROR))
        ->exitCode(0));

    $session = new ParallelStreamSession(
        server: $server,
        processes: ['parallel:0' => $process],
        branchIds: [],
        token: 'token',
        maxFrameBytes: 1_024,
        deadline: (float) hrtime(true) + 2_000_000_000,
        cancelGraceMilliseconds: 10,
    );

    iterator_to_array($session->events(), false);
}

beforeEach(function (): void {
    DeserializationProbe::$woken = 0;
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
