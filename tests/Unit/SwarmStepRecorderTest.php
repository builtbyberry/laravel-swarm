<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\SwarmStepRecorder;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Event;

function makeStepRecorderState(?callable $verifyOwnership = null): array
{
    $history = new class implements RunHistoryStore
    {
        public array $steps = [];

        public function start(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, int $ttlSeconds): void {}

        public function recordStep(string $runId, SwarmStep $step, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
        {
            $this->steps[] = $step;
        }

        public function complete(string $runId, SwarmResponse $response, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void {}

        public function fail(string $runId, Throwable $exception, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void {}

        public function find(string $runId): ?array
        {
            return null;
        }

        public function findMatching(string $swarmClass, ?string $status, ?array $contextSubset): iterable
        {
            return [];
        }

        public function query(?string $swarmClass = null, ?string $status = null, int $limit = 25): array
        {
            return [];
        }
    };

    $contextStore = new class implements ContextStore
    {
        public int $puts = 0;

        public ?RunContext $lastContext = null;

        public function put(RunContext $context, int $ttlSeconds): void
        {
            $this->puts++;
            $this->lastContext = clone $context;
        }

        public function find(string $runId): ?array
        {
            return null;
        }
    };

    $artifacts = new class implements ArtifactRepository
    {
        public array $stored = [];

        public function storeMany(string $runId, array $artifacts, int $ttlSeconds): void
        {
            array_push($this->stored, ...$artifacts);
        }

        public function all(string $runId): array
        {
            return [];
        }
    };

    $context = RunContext::from('original task', 'recorder-run-id');
    $context->mergeMetadata(['existing' => 'metadata']);

    $state = new SwarmExecutionState(
        swarm: new FakeSequentialSwarm,
        topology: Topology::Sequential,
        executionMode: ExecutionMode::Run,
        deadlineMonotonic: hrtime(true) + 1_000_000_000,
        maxAgentExecutions: 10,
        ttlSeconds: 3600,
        leaseSeconds: 300,
        executionToken: 'token',
        verifyOwnership: $verifyOwnership,
        context: $context,
        contextStore: $contextStore,
        artifactRepository: $artifacts,
        historyStore: $history,
        events: app('events'),
    );

    return [$state, $history, $contextStore, $artifacts];
}

test('started dispatches a swarm step started event', function () {
    Event::fake();
    [$state] = makeStepRecorderState();

    app(SwarmStepRecorder::class)->started($state, 2, FakeResearcher::class, 'research this');

    Event::assertDispatched(SwarmStepStarted::class, fn (SwarmStepStarted $event): bool => $event->runId === 'recorder-run-id'
        && $event->swarmClass === FakeSequentialSwarm::class
        && $event->index === 2
        && $event->agentClass === FakeResearcher::class
        && $event->input === 'research this'
        && $event->metadata === ['existing' => 'metadata']);
});

test('completed builds and persists the step lifecycle', function () {
    Event::fake();
    [$state, $history, $contextStore, $artifacts] = makeStepRecorderState();

    $step = app(SwarmStepRecorder::class)->completed(
        state: $state,
        index: 1,
        agentClass: FakeResearcher::class,
        input: 'input',
        output: 'output',
        usage: ['tokens' => 12],
        durationMs: 25,
        metadata: ['node_id' => 'research_node'],
        contextUsage: ['tokens' => 12],
    );

    expect($step)->toBeInstanceOf(SwarmStep::class);
    expect($step->metadata)->toBe(['index' => 1, 'usage' => ['tokens' => 12], 'node_id' => 'research_node']);
    expect($step->artifacts[0])->toBeInstanceOf(SwarmArtifact::class);
    expect($history->steps)->toHaveCount(1);
    expect($contextStore->puts)->toBe(1);
    expect($artifacts->stored)->toHaveCount(1);
    expect($state->context->data['last_output'])->toBe('output');
    expect($state->context->data['steps'])->toBe(2);
    expect($state->context->metadata['last_agent'])->toBe(FakeResearcher::class);
    expect($state->context->metadata['usage'])->toBe(['tokens' => 12]);

    Event::assertDispatched(SwarmStepCompleted::class, fn (SwarmStepCompleted $event): bool => $event->runId === 'recorder-run-id'
        && $event->swarmClass === FakeSequentialSwarm::class
        && $event->topology === 'sequential'
        && $event->index === 1
        && $event->agentClass === FakeResearcher::class
        && $event->input === 'input'
        && $event->output === 'output'
        && $event->durationMs === 25
        && $event->metadata === $step->metadata);
});

test('completed can record a step without changing context output fields', function () {
    Event::fake();
    [$state, $history, $contextStore, $artifacts] = makeStepRecorderState();

    app(SwarmStepRecorder::class)->completed(
        state: $state,
        index: 0,
        agentClass: FakeResearcher::class,
        input: 'input',
        output: 'branch output',
        usage: [],
        durationMs: 10,
        updateContext: false,
    );

    expect($history->steps)->toHaveCount(1);
    expect($contextStore->puts)->toBe(1);
    expect($artifacts->stored)->toHaveCount(1);
    expect($state->context->data)->not->toHaveKey('last_output');
    expect($state->context->data)->not->toHaveKey('steps');
    expect($state->context->metadata)->not->toHaveKey('last_agent');
});

test('completed stops before persistence when ownership is already lost', function () {
    Event::fake();
    [$state, $history, $contextStore, $artifacts] = makeStepRecorderState(
        fn () => throw new LostSwarmLeaseException('lost lease'),
    );

    expect(fn () => app(SwarmStepRecorder::class)->completed(
        state: $state,
        index: 0,
        agentClass: FakeResearcher::class,
        input: 'input',
        output: 'output',
        usage: [],
        durationMs: 10,
    ))->toThrow(LostSwarmLeaseException::class, 'lost lease');

    expect($history->steps)->toBe([]);
    expect($contextStore->puts)->toBe(0);
    expect($artifacts->stored)->toBe([]);
    Event::assertNotDispatched(SwarmStepCompleted::class);
});

test('completed stops between persistence side effects when ownership is lost', function () {
    Event::fake();
    $checks = 0;
    [$state, $history, $contextStore, $artifacts] = makeStepRecorderState(function () use (&$checks): void {
        $checks++;

        if ($checks === 2) {
            throw new LostSwarmLeaseException('lost lease after history');
        }
    });

    expect(fn () => app(SwarmStepRecorder::class)->completed(
        state: $state,
        index: 0,
        agentClass: FakeResearcher::class,
        input: 'input',
        output: 'output',
        usage: [],
        durationMs: 10,
    ))->toThrow(LostSwarmLeaseException::class, 'lost lease after history');

    expect($history->steps)->toHaveCount(1);
    expect($contextStore->puts)->toBe(0);
    expect($artifacts->stored)->toBe([]);
    Event::assertNotDispatched(SwarmStepCompleted::class);
});
