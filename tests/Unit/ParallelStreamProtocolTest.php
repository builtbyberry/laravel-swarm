<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Streaming\Parallel\ParallelStreamHandshakeRegistry;
use BuiltByBerry\LaravelSwarm\Streaming\Parallel\ParallelStreamProtocol;

test('parallel stream frames enforce atomic byte bounds', function (): void {
    [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $deadline = (float) hrtime(true) + 1_000_000_000;

    try {
        expect(fn () => ParallelStreamProtocol::writeFrame($writer, ['payload' => str_repeat('x', 128)], 32, $deadline))
            ->toThrow(SwarmException::class, 'branch event and terminal outcome frames are atomic and are not split');
    } finally {
        fclose($writer);
        fclose($reader);
    }
});

test('parallel stream frames reject oversized and truncated input before allocation', function (string $wire, string $message): void {
    [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($writer, $wire);
    fclose($writer);

    try {
        expect(fn () => ParallelStreamProtocol::readFrame($reader, 64, (float) hrtime(true) + 100_000_000))
            ->toThrow(SwarmException::class, $message);
    } finally {
        fclose($reader);
    }
})->with([
    'oversized' => [pack('N', 65), 'invalid or oversized frame length'],
    'truncated' => [pack('N', 8).'{}', 'disconnected before a complete frame'],
]);

test('parallel stream frames reject malformed json without exposing payload bytes', function (): void {
    [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($writer, pack('N', 2).'xx');

    try {
        expect(fn () => ParallelStreamProtocol::readFrame($reader, 64, (float) hrtime(true) + 100_000_000))
            ->toThrow(SwarmException::class, 'received malformed JSON');
    } finally {
        fclose($writer);
        fclose($reader);
    }
});

test('parallel stream handshakes reject unauthenticated and duplicate connections', function (): void {
    $registry = new ParallelStreamHandshakeRegistry('secret', ['parallel:0']);

    expect(fn () => $registry->accept([
        'v' => ParallelStreamProtocol::VERSION,
        'type' => 'hello',
        'token' => 'wrong',
        'branch_id' => 'parallel:0',
    ]))->toThrow(SwarmException::class, 'unauthenticated branch connection');

    expect($registry->accept([
        'v' => ParallelStreamProtocol::VERSION,
        'type' => 'hello',
        'token' => 'secret',
        'branch_id' => 'parallel:0',
    ]))->toBe('parallel:0');

    expect(fn () => $registry->accept([
        'v' => ParallelStreamProtocol::VERSION,
        'type' => 'hello',
        'token' => 'secret',
        'branch_id' => 'parallel:0',
    ]))->toThrow(SwarmException::class, 'duplicate branch [parallel:0]');
});
