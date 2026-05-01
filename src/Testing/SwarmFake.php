<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Broadcasting\Channel;
use Illuminate\Testing\Assert as PHPUnit;
use Laravel\Ai\FakePendingDispatch;

/**
 * Test double that records calls for assertions.
 *
 * {@see queue()} and {@see assertQueued()} capture dispatch intent only: they do not run {@see SwarmRunner}
 * or simulate coordinated hierarchical multi_worker parallel joins (branch execution, durable coordination state, resume jobs).
 * Cover that behavior with persisted integration/feature tests instead.
 */
class SwarmFake implements Swarm
{
    /**
     * @var array<int, string|array<string, mixed>|RunContext>
     */
    protected array $recorded = [];

    /**
     * @var array<int, string|array<string, mixed>|RunContext>
     */
    protected array $recordedQueued = [];

    /**
     * @var array<int, string|array<string, mixed>|RunContext>
     */
    protected array $recordedDurable = [];

    /**
     * @var array<int, string|array<string, mixed>|RunContext>
     */
    protected array $recordedStreamed = [];

    /**
     * @var array<string, array<int, mixed>>
     */
    protected array $recordedDurableOperations = [
        'signals' => [],
        'waits' => [],
        'progress' => [],
        'labels' => [],
        'details' => [],
        'retries' => [],
        'children' => [],
        'operations' => [],
        'inspections' => [],
    ];

    /**
     * @param  class-string  $swarmClass
     * @param  array<int, string>|callable|null  $responses
     */
    public function __construct(
        protected string $swarmClass,
        protected mixed $responses = null,
    ) {}

    /**
     * Required by the Swarm contract — not used during faking.
     */
    public function agents(): array
    {
        return [];
    }

    /**
     * Intercept a prompt call and record it.
     */
    public function prompt(string|array|RunContext $task): SwarmResponse
    {
        $this->recorded[] = $task;

        $output = $this->resolveResponse($task);

        return new SwarmResponse(
            output: $output,
            metadata: ['run_id' => 'fake-run-id'],
        );
    }

    /**
     * Intercept a run call and record it.
     */
    public function run(string|array|RunContext $task): SwarmResponse
    {
        return $this->prompt($task);
    }

    /**
     * Intercept a queue call and record it.
     */
    public function queue(string|array|RunContext $task): QueuedSwarmResponse
    {
        $this->recordedQueued[] = $task;

        return new QueuedSwarmResponse(new FakePendingDispatch, 'fake-run-id');
    }

    public function dispatchDurable(string|array|RunContext $task): DurableSwarmResponse
    {
        $this->recordedDurable[] = $task;

        return new DurableSwarmResponse(
            new FakePendingDispatch,
            new FakeDurableSwarmManager($this),
            'fake-run-id',
        );
    }

    public function recordDurableSignal(string $name, mixed $payload = null, ?string $idempotencyKey = null): void
    {
        $this->recordedDurableOperations['signals'][] = compact('name', 'payload', 'idempotencyKey');
    }

    public function recordDurableWait(string $name, ?string $reason = null, ?int $timeoutSeconds = null, array $metadata = []): self
    {
        $this->recordedDurableOperations['waits'][] = compact('name', 'reason', 'timeoutSeconds', 'metadata');

        return $this;
    }

    public function recordDurableProgress(array $progress, ?string $branchId = null): self
    {
        $this->recordedDurableOperations['progress'][] = compact('progress', 'branchId');

        return $this;
    }

    public function recordDurableLabels(array $labels): self
    {
        $this->recordedDurableOperations['labels'][] = $labels;

        return $this;
    }

    public function recordDurableDetails(array $details): self
    {
        $this->recordedDurableOperations['details'][] = $details;

        return $this;
    }

    public function recordDurableRetry(array $policy): self
    {
        $this->recordedDurableOperations['retries'][] = $policy;

        return $this;
    }

    public function recordDurableChildSwarm(string $childSwarmClass, string|array|RunContext $task): self
    {
        $this->recordedDurableOperations['children'][] = compact('childSwarmClass', 'task');

        return $this;
    }

    public function recordDurableOperation(string $operation): void
    {
        $this->recordedDurableOperations['operations'][] = $operation;
    }

    public function recordDurableInspect(): void
    {
        $this->recordedDurableOperations['inspections'][] = true;
    }

    public function durableRunDetail(string $runId): DurableRunDetail
    {
        return new DurableRunDetail(
            runId: $runId,
            run: ['run_id' => $runId, 'status' => 'fake'],
            labels: array_merge(...($this->recordedDurableOperations['labels'] ?: [[]])),
            details: array_merge(...($this->recordedDurableOperations['details'] ?: [[]])),
            waits: $this->recordedDurableOperations['waits'],
            signals: $this->recordedDurableOperations['signals'],
            progress: $this->recordedDurableOperations['progress'],
            children: $this->recordedDurableOperations['children'],
        );
    }

    /**
     * Intercept a stream call and record it.
     */
    public function stream(string|array|RunContext $task): StreamableSwarmResponse
    {
        return new StreamableSwarmResponse('fake-run-id', function () use ($task): \Generator {
            $this->recordedStreamed[] = $task;
            $output = $this->resolveResponse($task);

            yield new SwarmStreamStart(
                id: SwarmStreamEvent::newId(),
                runId: 'fake-run-id',
                swarmClass: $this->swarmClass,
                topology: 'sequential',
                input: is_string($task) ? $task : 'structured-task',
                metadata: ['run_id' => 'fake-run-id'],
                timestamp: SwarmStreamEvent::timestamp(),
            );
            yield new SwarmStepStart(
                id: SwarmStreamEvent::newId(),
                runId: 'fake-run-id',
                stepIndex: 0,
                agentClass: self::class,
                agent: 'SwarmFake',
                input: is_string($task) ? $task : 'structured-task',
                timestamp: SwarmStreamEvent::timestamp(),
            );
            yield new SwarmTextDelta(
                id: SwarmStreamEvent::newId(),
                runId: 'fake-run-id',
                stepIndex: 0,
                agentClass: self::class,
                delta: $output,
                timestamp: SwarmStreamEvent::timestamp(),
            );
            yield new SwarmStepEnd(
                id: SwarmStreamEvent::newId(),
                runId: 'fake-run-id',
                stepIndex: 0,
                agentClass: self::class,
                agent: 'SwarmFake',
                output: $output,
                durationMs: 0,
                metadata: [],
                timestamp: SwarmStreamEvent::timestamp(),
            );
            yield new SwarmStreamEnd(
                id: SwarmStreamEvent::newId(),
                runId: 'fake-run-id',
                output: $output,
                usage: [],
                metadata: ['run_id' => 'fake-run-id'],
                timestamp: SwarmStreamEvent::timestamp(),
            );

            return new SwarmResponse(
                output: $output,
                metadata: ['run_id' => 'fake-run-id'],
            );
        });
    }

    /**
     * Intercept a broadcast call and record it as a stream.
     */
    public function broadcast(string|array|RunContext $task, Channel|array $channels, bool $now = false): StreamableSwarmResponse
    {
        return $this->stream($task)
            ->each(function (SwarmStreamEvent $event) use ($channels, $now): void {
                $event->{$now ? 'broadcastNow' : 'broadcast'}($channels);
            });
    }

    /**
     * Intercept an immediate broadcast call and record it as a stream.
     */
    public function broadcastNow(string|array|RunContext $task, Channel|array $channels): StreamableSwarmResponse
    {
        return $this->broadcast($task, $channels, now: true);
    }

    /**
     * Intercept a queued broadcast call and record it as queued.
     */
    public function broadcastOnQueue(string|array|RunContext $task, Channel|array $channels): QueuedSwarmResponse
    {
        return $this->queue($task);
    }

    /**
     * Assert the swarm was prompted with the given task.
     */
    public function assertPrompted(string|array|callable $task): void
    {
        $this->assertRan($task);
    }

    /**
     * Assert the swarm was run with the given task.
     */
    public function assertRan(string|array|callable $task): void
    {
        if (is_callable($task)) {
            PHPUnit::assertTrue(
                collect($this->recorded)->contains(fn ($recorded) => $task($recorded)),
                "The swarm [{$this->swarmClass}] was not run with the expected task.",
            );

            return;
        }

        if (is_array($task)) {
            PHPUnit::assertTrue(
                collect($this->recorded)->contains(fn ($recorded) => $this->matchesStructuredTask($task, $recorded)),
                "The swarm [{$this->swarmClass}] was not run with the expected structured task subset.",
            );

            return;
        }

        PHPUnit::assertContains($task, $this->recorded, "The swarm [{$this->swarmClass}] was not run with task: [{$task}].");
    }

    /**
     * Assert the swarm was never prompted.
     */
    public function assertNeverPrompted(): void
    {
        $this->assertNeverRan();
    }

    /**
     * Assert the swarm was never run.
     */
    public function assertNeverRan(): void
    {
        PHPUnit::assertEmpty(
            $this->recorded,
            "The swarm [{$this->swarmClass}] was run unexpectedly.",
        );
    }

    /**
     * Assert the swarm was queued with the given task.
     */
    public function assertQueued(string|array|callable $task): void
    {
        if (is_callable($task)) {
            PHPUnit::assertTrue(
                collect($this->recordedQueued)->contains(fn ($recorded) => $task($recorded)),
                "The swarm [{$this->swarmClass}] was not queued with the expected task.",
            );

            return;
        }

        if (is_array($task)) {
            PHPUnit::assertTrue(
                collect($this->recordedQueued)->contains(fn ($recorded) => $this->matchesStructuredTask($task, $recorded)),
                "The swarm [{$this->swarmClass}] was not queued with the expected structured task subset.",
            );

            return;
        }

        PHPUnit::assertContains($task, $this->recordedQueued, "The swarm [{$this->swarmClass}] was not queued with task: [{$task}].");
    }

    /**
     * Assert the swarm was never queued.
     */
    public function assertNeverQueued(): void
    {
        PHPUnit::assertEmpty(
            $this->recordedQueued,
            "The swarm [{$this->swarmClass}] was queued unexpectedly.",
        );
    }

    public function assertDispatchedDurably(string|array|callable $task): void
    {
        if (is_callable($task)) {
            PHPUnit::assertTrue(
                collect($this->recordedDurable)->contains(fn ($recorded) => $task($recorded)),
                "The swarm [{$this->swarmClass}] was not durably dispatched with the expected task.",
            );

            return;
        }

        if (is_array($task)) {
            PHPUnit::assertTrue(
                collect($this->recordedDurable)->contains(fn ($recorded) => $this->matchesStructuredTask($task, $recorded)),
                "The swarm [{$this->swarmClass}] was not durably dispatched with the expected structured task subset.",
            );

            return;
        }

        PHPUnit::assertContains($task, $this->recordedDurable, "The swarm [{$this->swarmClass}] was not durably dispatched with task: [{$task}].");
    }

    public function assertNeverDispatchedDurably(): void
    {
        PHPUnit::assertEmpty(
            $this->recordedDurable,
            "The swarm [{$this->swarmClass}] was durably dispatched unexpectedly.",
        );
    }

    public function assertDurableSignalled(string|callable $name): void
    {
        $this->assertRecordedDurableOperation('signals', $name, 'name', 'durable signal');
    }

    public function assertDurableWaited(string|callable $name): void
    {
        $this->assertRecordedDurableOperation('waits', $name, 'name', 'durable wait');
    }

    public function assertDurableProgressRecorded(array|callable $progress): void
    {
        if (is_callable($progress)) {
            $this->assertRecordedDurableOperation('progress', $progress, null, 'durable progress');

            return;
        }

        PHPUnit::assertTrue(
            collect($this->recordedDurableOperations['progress'])->contains(fn (array $record): bool => $this->arraySubsetMatches($progress, $record['progress'] ?? [])),
            "The swarm [{$this->swarmClass}] did not record durable progress matching the expected subset.",
        );
    }

    public function assertDurableLabels(array|callable $labels): void
    {
        $this->assertRecordedDurableArraySubset('labels', $labels, 'durable labels');
    }

    public function assertDurableDetails(array|callable $details): void
    {
        $this->assertRecordedDurableArraySubset('details', $details, 'durable details');
    }

    public function assertDurableRetryScheduled(array|callable $policy): void
    {
        $this->assertRecordedDurableArraySubset('retries', $policy, 'durable retry');
    }

    public function assertDurableChildSwarmDispatched(string|callable $childSwarmClass): void
    {
        $this->assertRecordedDurableOperation('children', $childSwarmClass, 'childSwarmClass', 'durable child swarm');
    }

    /**
     * Assert the swarm was streamed with the given task.
     */
    public function assertStreamed(string|array|callable $task): void
    {
        if (is_callable($task)) {
            PHPUnit::assertTrue(
                collect($this->recordedStreamed)->contains(fn ($recorded) => $task($recorded)),
                "The swarm [{$this->swarmClass}] was not streamed with the expected task.",
            );

            return;
        }

        if (is_array($task)) {
            PHPUnit::assertTrue(
                collect($this->recordedStreamed)->contains(fn ($recorded) => $this->matchesStructuredTask($task, $recorded)),
                "The swarm [{$this->swarmClass}] was not streamed with the expected structured task subset.",
            );

            return;
        }

        PHPUnit::assertContains($task, $this->recordedStreamed, "The swarm [{$this->swarmClass}] was not streamed with task: [{$task}].");
    }

    /**
     * Assert the swarm was never streamed.
     */
    public function assertNeverStreamed(): void
    {
        PHPUnit::assertEmpty(
            $this->recordedStreamed,
            "The swarm [{$this->swarmClass}] was streamed unexpectedly.",
        );
    }

    /**
     * Resolve the fake response for the given task.
     */
    protected function resolveResponse(string|array|RunContext $task): string
    {
        if (is_callable($this->responses)) {
            return ($this->responses)($task);
        }

        if (is_array($this->responses) && $this->responses !== []) {
            return array_shift($this->responses);
        }

        return "Fake response for swarm [{$this->swarmClass}].";
    }

    protected function assertRecordedDurableOperation(string $bucket, string|callable $expected, ?string $key, string $label): void
    {
        if (is_callable($expected)) {
            PHPUnit::assertTrue(
                collect($this->recordedDurableOperations[$bucket])->contains(fn ($record): bool => (bool) $expected($record)),
                "The swarm [{$this->swarmClass}] did not record the expected {$label}.",
            );

            return;
        }

        PHPUnit::assertTrue(
            collect($this->recordedDurableOperations[$bucket])->contains(fn ($record): bool => is_array($record) && $key !== null && ($record[$key] ?? null) === $expected),
            "The swarm [{$this->swarmClass}] did not record {$label} [{$expected}].",
        );
    }

    protected function assertRecordedDurableArraySubset(string $bucket, array|callable $expected, string $label): void
    {
        if (is_callable($expected)) {
            PHPUnit::assertTrue(
                collect($this->recordedDurableOperations[$bucket])->contains(fn ($record): bool => (bool) $expected($record)),
                "The swarm [{$this->swarmClass}] did not record the expected {$label}.",
            );

            return;
        }

        PHPUnit::assertTrue(
            collect($this->recordedDurableOperations[$bucket])->contains(fn ($record): bool => $this->arraySubsetMatches($expected, $record)),
            "The swarm [{$this->swarmClass}] did not record {$label} matching the expected subset.",
        );
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  string|array<string, mixed>|RunContext  $actual
     */
    protected function matchesStructuredTask(array $expected, string|array|RunContext $actual): bool
    {
        if ($actual instanceof RunContext) {
            $context = [
                'input' => $actual->input,
                'data' => $actual->data,
                'metadata' => $actual->metadata,
            ];

            return $this->arraySubsetMatches($expected, $context)
                || $this->arraySubsetMatches($expected, $context['data'])
                || $this->arraySubsetMatches($expected, $context['metadata']);
        }

        if (! is_array($actual)) {
            return false;
        }

        if ($this->isContextPayload($actual)) {
            return $this->arraySubsetMatches($expected, $actual)
                || $this->arraySubsetMatches($expected, is_array($actual['data'] ?? null) ? $actual['data'] : [])
                || $this->arraySubsetMatches($expected, is_array($actual['metadata'] ?? null) ? $actual['metadata'] : []);
        }

        return $this->arraySubsetMatches($expected, $actual);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    protected function arraySubsetMatches(array $expected, mixed $actual): bool
    {
        if (! is_array($actual)) {
            return false;
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }

            if (is_array($value)) {
                if (! $this->arraySubsetMatches($value, $actual[$key])) {
                    return false;
                }

                continue;
            }

            if ($actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    protected function isContextPayload(array $task): bool
    {
        return array_key_exists('run_id', $task)
            || array_key_exists('input', $task)
            || array_key_exists('data', $task)
            || array_key_exists('metadata', $task)
            || array_key_exists('artifacts', $task);
    }
}
