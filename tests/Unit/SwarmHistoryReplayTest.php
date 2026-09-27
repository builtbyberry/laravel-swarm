<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use Illuminate\Config\Repository;

final class RejectingReplayRunHistoryStore implements RunHistoryStore
{
    public function start(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, int $ttlSeconds): void
    {
        throw new LogicException('Replay must not write history.');
    }

    public function recordStep(string $runId, SwarmStep $step, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        throw new LogicException('Replay must not write history.');
    }

    public function complete(string $runId, SwarmResponse $response, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        throw new LogicException('Replay must not write history.');
    }

    public function fail(string $runId, Throwable $exception, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        throw new LogicException('Replay must not write history.');
    }

    public function recordPreflightFailure(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, Throwable $exception, int $ttlSeconds): void
    {
        throw new LogicException('Replay must not write history.');
    }

    public function find(string $runId): ?array
    {
        throw new LogicException('Replay must not hydrate run history.');
    }

    public function findMatching(string $swarmClass, ?string $status, ?array $contextSubset): iterable
    {
        throw new LogicException('Replay must not query run history.');
    }

    public function query(?string $swarmClass = null, ?string $status = null, int $limit = 25): array
    {
        throw new LogicException('Replay must not query run history.');
    }
}

final class CountingReplayStreamEventStore implements StreamEventStore
{
    public int $iterations = 0;

    public function __construct(private readonly string $topology) {}

    public function record(string $runId, SwarmStreamEvent $event, int $ttlSeconds): void {}

    public function forget(string $runId): void {}

    public function events(string $runId): iterable
    {
        $this->iterations++;

        yield new SwarmStreamStart(
            id: 'start',
            runId: $runId,
            swarmClass: 'ExampleSwarm',
            topology: $this->topology,
            input: null,
            metadata: [],
            timestamp: 1,
        );
    }
}

test('two argument history construction keeps replay lazy and native protocols disabled', function () {
    $historyStore = new RejectingReplayRunHistoryStore;
    $streamEvents = new CountingReplayStreamEventStore('sequential');

    $replay = (new SwarmHistory($historyStore, $streamEvents))->replay('run-1');

    expect($streamEvents->iterations)->toBe(0)
        ->and(fn () => $replay->usingVercelDataProtocol('message-1'))
        ->toThrow(SwarmException::class, 'Native chat protocol projection is disabled.')
        ->and($streamEvents->iterations)->toBe(0);
});

test('workflow replay does not resolve topology before source iteration', function () {
    $historyStore = new RejectingReplayRunHistoryStore;
    $streamEvents = new CountingReplayStreamEventStore('parallel');
    $config = new Repository([
        'swarm' => ['streaming' => ['native_protocols' => ['enabled' => true]]],
    ]);

    $replay = (new SwarmHistory($historyStore, $streamEvents, $config))->replay('run-2');
    $replay->usingVercelDataProtocol('message-2');

    expect($streamEvents->iterations)->toBe(0);
});

test('final agent replay resolves topology once and rejects before replay iteration', function () {
    $historyStore = new RejectingReplayRunHistoryStore;
    $streamEvents = new CountingReplayStreamEventStore('parallel');
    $config = new Repository([
        'swarm' => ['streaming' => ['native_protocols' => ['enabled' => true]]],
    ]);

    $replay = (new SwarmHistory($historyStore, $streamEvents, $config))->replay('run-3');

    expect(fn () => $replay->usingVercelDataProtocol('message-3', NativeProtocolProjection::FinalAgent))
        ->toThrow(SwarmException::class, 'The final-agent native protocol projection is supported only for sequential swarms.')
        ->and($streamEvents->iterations)->toBe(1);

    expect(fn () => $replay->usingVercelDataProtocol('message-3', NativeProtocolProjection::FinalAgent))
        ->toThrow(SwarmException::class, 'The final-agent native protocol projection is supported only for sequential swarms.')
        ->and($streamEvents->iterations)->toBe(1);
});

test('final agent replay accepts a sequential topology without starting replay', function () {
    $historyStore = new RejectingReplayRunHistoryStore;
    $streamEvents = new CountingReplayStreamEventStore('sequential');
    $config = new Repository([
        'swarm' => ['streaming' => ['native_protocols' => ['enabled' => true]]],
    ]);

    $replay = (new SwarmHistory($historyStore, $streamEvents, $config))->replay('run-4');
    $replay->usingAgentUserInteractionProtocol(
        'thread-4',
        projection: NativeProtocolProjection::FinalAgent,
    );

    expect($streamEvents->iterations)->toBe(1);
});
