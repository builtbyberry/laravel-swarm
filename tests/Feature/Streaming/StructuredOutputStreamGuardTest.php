<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\StructuredOutputStreamingException;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SequentialStructuredWorkerStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StaticHierarchicalStructuredWorkerStreamSwarm;

// These prove the LIVE stream() surfaces fail loud with a swarm-domain error — never
// laravel/ai's bare InvalidArgumentException — the moment a structured-output worker
// would be streamed. The durable surfaces are guarded earlier, at dispatch (see
// DispatchValidatorTest).

test('a live sequential stream() fails loud when its streamed agent is structured-output (#321)', function (): void {
    expect(fn (): array => iterator_to_array(SequentialStructuredWorkerStreamSwarm::make()->stream('task')))
        ->toThrow(StructuredOutputStreamingException::class, 'cannot be streamed');
});

test('a live static-hierarchical stream() fails loud when a worker is structured-output (#321)', function (): void {
    expect(fn (): array => iterator_to_array(StaticHierarchicalStructuredWorkerStreamSwarm::make()->stream('task')))
        ->toThrow(StructuredOutputStreamingException::class, 'cannot be streamed');
});
