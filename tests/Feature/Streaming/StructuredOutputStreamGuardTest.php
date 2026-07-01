<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\StructuredOutputStreamingException;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SequentialStructuredWorkerStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StaticHierarchicalStructuredWorkerStreamSwarm;

// These prove the LIVE stream() surfaces fail loud with a swarm-domain error — never
// laravel/ai's bare InvalidArgumentException — the moment a structured-output worker
// would be streamed. The durable surfaces are guarded earlier, at dispatch (see
// DispatchValidatorTest), but the per-site guard is a real backstop too (see the
// durable streamSingleStep test below).

test('a live sequential stream() fails loud when its streamed agent is structured-output (#321)', function (): void {
    expect(fn (): array => iterator_to_array(SequentialStructuredWorkerStreamSwarm::make()->stream('task')))
        ->toThrow(StructuredOutputStreamingException::class, 'cannot be streamed');
});

test('a live static-hierarchical stream() fails loud when a worker is structured-output (#321)', function (): void {
    expect(fn (): array => iterator_to_array(StaticHierarchicalStructuredWorkerStreamSwarm::make()->stream('task')))
        ->toThrow(StructuredOutputStreamingException::class, 'cannot be streamed');
});

test('the durable per-node stream site guards a structured-output worker even past dispatch (#321 backstop)', function (): void {
    // The dispatch guard normally rejects this before any job runs. This drives the
    // durable stream site (SequentialRunner::streamSingleStep) directly to prove the
    // per-site guard is a genuine backstop — e.g. if agents() were to resolve a
    // structured-output worker at execution that was absent at dispatch. The guard
    // throws before the sink or the agent's stream() is ever touched.
    $state = new SwarmExecutionState(
        swarm: new SequentialStructuredWorkerStreamSwarm,
        topology: Topology::Sequential,
        executionMode: ExecutionMode::Durable,
        deadlineMonotonic: hrtime(true) + 1_000_000_000,
        maxAgentExecutions: 10,
        ttlSeconds: 3600,
        leaseSeconds: null,
        executionToken: null,
        verifyOwnership: null,
        context: RunContext::from('task', 'run-321-backstop'),
        contextStore: app(ContextStore::class),
        artifactRepository: app(ArtifactRepository::class),
        historyStore: app(RunHistoryStore::class),
        events: app('events'),
        queueHierarchicalParallelCoordination: null,
    );

    expect(fn (): mixed => app(SequentialRunner::class)->streamSingleStep($state, 0, fn (object $event) => null))
        ->toThrow(StructuredOutputStreamingException::class, 'cannot be streamed');
});
