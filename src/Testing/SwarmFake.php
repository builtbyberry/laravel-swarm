<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing;

use BuiltByBerry\LaravelSwarm\Attributes\Topology as TopologyAttribute;
use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\DurableLifecycleStatus;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;
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
use BuiltByBerry\LaravelSwarm\Testing\Audit\RecordingCapturePolicy;
use BuiltByBerry\LaravelSwarm\Testing\Audit\RecordingSinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Testing\Audit\RecordingSwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Testing\Audit\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Testing\Memory\RecordingMemoryCapturePolicy;
use Illuminate\Broadcasting\Channel;
use Illuminate\Container\Container;
use Illuminate\Testing\Assert as PHPUnit;
use Laravel\Ai\FakePendingDispatch;

/**
 * Test double that records calls for assertions.
 *
 * {@see queue()} and {@see assertQueued()} capture dispatch intent only: they do not run {@see SwarmRunner}
 * or simulate coordinated hierarchical multi_worker parallel joins (branch execution, durable coordination state, resume jobs).
 * Cover that behavior with persisted integration/feature tests instead.
 *
 * @phpstan-import-type SwarmTaskInput from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmAssertTask from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmStructuredSubset from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
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

    private string $fakeTopology;

    private DurableLifecycleStatus $fakePauseStatus = DurableLifecycleStatus::Paused;

    private DurableLifecycleStatus $fakeResumeStatus = DurableLifecycleStatus::Resumed;

    private DurableLifecycleStatus $fakeCancelStatus = DurableLifecycleStatus::Cancelled;

    /**
     * @param  class-string  $swarmClass
     * @param  array<int, string>|callable|null  $responses
     */
    public function __construct(
        protected string $swarmClass,
        protected mixed $responses = null,
    ) {
        $reflection = new \ReflectionClass($swarmClass);
        $attributes = $reflection->getAttributes(TopologyAttribute::class);
        $this->fakeTopology = $attributes !== []
            ? $attributes[0]->newInstance()->topology->value
            : TopologyEnum::Sequential->value;
    }

    /**
     * Required by the Swarm contract — not used during faking.
     *
     * @return array<int, Agent>
     */
    public function agents(): array
    {
        return [];
    }

    /**
     * Intercept a prompt call and record it.
     *
     * @param  SwarmTaskInput  $task
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
     *
     * @param  SwarmTaskInput  $task
     */
    public function run(string|array|RunContext $task): SwarmResponse
    {
        return $this->prompt($task);
    }

    /**
     * Intercept a queue call and record it.
     *
     * @param  SwarmTaskInput  $task
     */
    public function queue(string|array|RunContext $task): QueuedSwarmResponse
    {
        $this->recordedQueued[] = $task;

        return new QueuedSwarmResponse(new FakePendingDispatch, 'fake-run-id');
    }

    /**
     * @param  SwarmTaskInput  $task
     */
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

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordDurableWait(string $name, ?string $reason = null, ?int $timeoutSeconds = null, array $metadata = []): self
    {
        $this->recordedDurableOperations['waits'][] = compact('name', 'reason', 'timeoutSeconds', 'metadata');

        return $this;
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    public function recordDurableProgress(array $progress, ?string $branchId = null): self
    {
        $this->recordedDurableOperations['progress'][] = compact('progress', 'branchId');

        return $this;
    }

    /**
     * @param  array<string, mixed>  $labels
     */
    public function recordDurableLabels(array $labels): self
    {
        $this->recordedDurableOperations['labels'][] = $labels;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function recordDurableDetails(array $details): self
    {
        $this->recordedDurableOperations['details'][] = $details;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    public function recordDurableRetry(array $policy): self
    {
        $this->recordedDurableOperations['retries'][] = $policy;

        return $this;
    }

    /**
     * @param  SwarmTaskInput  $task
     */
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

    /**
     * @return class-string
     */
    public function fakeSwarmClass(): string
    {
        return $this->swarmClass;
    }

    public function fakeTopology(): string
    {
        return $this->fakeTopology;
    }

    /**
     * Configure the lifecycle statuses the fake's pause/resume/cancel return.
     *
     * By default the fake models only the IMMEDIATE branch
     * (`Paused`/`Resumed`/`Cancelled`). Use this to drive the SCHEDULED /
     * WAITING branch in a consumer test double — e.g.
     * `->fakeDurableLifecycleStatus(pause: DurableLifecycleStatus::PauseScheduled)`.
     */
    public function fakeDurableLifecycleStatus(
        ?DurableLifecycleStatus $pause = null,
        ?DurableLifecycleStatus $resume = null,
        ?DurableLifecycleStatus $cancel = null,
    ): self {
        $this->fakePauseStatus = $pause ?? $this->fakePauseStatus;
        $this->fakeResumeStatus = $resume ?? $this->fakeResumeStatus;
        $this->fakeCancelStatus = $cancel ?? $this->fakeCancelStatus;

        return $this;
    }

    public function fakePauseStatus(): DurableLifecycleStatus
    {
        return $this->fakePauseStatus;
    }

    public function fakeResumeStatus(): DurableLifecycleStatus
    {
        return $this->fakeResumeStatus;
    }

    public function fakeCancelStatus(): DurableLifecycleStatus
    {
        return $this->fakeCancelStatus;
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
     *
     * @param  SwarmTaskInput  $task
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
                topology: $this->fakeTopology,
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
     *
     * @param  SwarmTaskInput  $task
     * @param  SwarmBroadcastChannels  $channels
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
     *
     * @param  SwarmTaskInput  $task
     * @param  SwarmBroadcastChannels  $channels
     */
    public function broadcastNow(string|array|RunContext $task, Channel|array $channels): StreamableSwarmResponse
    {
        return $this->broadcast($task, $channels, now: true);
    }

    /**
     * Intercept a queued broadcast call and record it as queued.
     *
     * @param  SwarmTaskInput  $task
     * @param  SwarmBroadcastChannels  $channels
     */
    public function broadcastOnQueue(string|array|RunContext $task, Channel|array $channels): QueuedSwarmResponse
    {
        return $this->queue($task);
    }

    /**
     * Assert the swarm was prompted with the given task.
     *
     * @param  SwarmAssertTask  $task
     */
    public function assertPrompted(string|array|callable $task): void
    {
        $this->assertRan($task);
    }

    /**
     * Assert the swarm was run with the given task.
     *
     * @param  SwarmAssertTask  $task
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
     *
     * @param  SwarmAssertTask  $task
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

    /**
     * @param  SwarmAssertTask  $task
     */
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

    /**
     * @param  SwarmStructuredSubset|callable  $progress
     */
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

    /**
     * @param  SwarmStructuredSubset|callable  $labels
     */
    public function assertDurableLabels(array|callable $labels): void
    {
        $this->assertRecordedDurableArraySubset('labels', $labels, 'durable labels');
    }

    /**
     * @param  SwarmStructuredSubset|callable  $details
     */
    public function assertDurableDetails(array|callable $details): void
    {
        $this->assertRecordedDurableArraySubset('details', $details, 'durable details');
    }

    /**
     * @param  SwarmStructuredSubset|callable  $policy
     */
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
     *
     * @param  SwarmAssertTask  $task
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
     * Assert at least one recorded dispatch carried a RunContext whose
     * metadata.actor matches the expected actor.
     *
     * Accepts an Actor value object (exact match on id+type), a "type:id"
     * shorthand string, or a callable that receives the resolved Actor and
     * returns a boolean.
     *
     * Bare-string and structured-array tasks (no RunContext) never match —
     * the actor only flows through RunContext::withActor(). Callers using
     * Context::add('swarm:actor', ...) should pass an explicit
     * $context->withActor($actor) before dispatch to make the binding visible
     * to SwarmFake.
     */
    public function assertDispatchedWithActor(Actor|string|callable $actor): void
    {
        PHPUnit::assertTrue(
            $this->recordedDispatchesMatchingActor($actor) !== [],
            "The swarm [{$this->swarmClass}] was not dispatched with the expected actor.",
        );
    }

    /**
     * Assert at least one recorded dispatch carried a RunContext with any
     * non-null actor in metadata.
     */
    public function assertDispatchedWithAnyActor(): void
    {
        PHPUnit::assertTrue(
            $this->collectRecordedActors() !== [],
            "The swarm [{$this->swarmClass}] was not dispatched with any actor.",
        );
    }

    /**
     * Assert no recorded dispatch carried an actor in metadata.
     */
    public function assertNeverDispatchedWithActor(): void
    {
        $actors = $this->collectRecordedActors();

        PHPUnit::assertEmpty(
            $actors,
            "The swarm [{$this->swarmClass}] was dispatched with an actor unexpectedly.",
        );
    }

    /**
     * Install a {@see RecordingCapturePolicy} as the bound {@see CapturePolicy}
     * for the duration of the test.
     *
     * The returned recorder exposes assertion helpers covering every
     * dispatcher invocation of the policy (inputs, outputs, artifacts,
     * activeContext). The optional delegate is forwarded to behind the
     * recording layer so existing policy logic still drives decisions;
     * pass null to record-and-return {@see CaptureDecision::Full}
     * for every category.
     *
     * Recording happens when the real audit dispatcher resolves the
     * contract from the container during a non-faked run. SwarmFake itself
     * never constructs or invokes the dispatcher — this helper only swaps
     * the container binding and flushes the dispatcher singleton so the
     * next resolution picks up the recorder.
     */
    public static function interceptCapturePolicy(?CapturePolicy $delegate = null): RecordingCapturePolicy
    {
        $recorder = new RecordingCapturePolicy($delegate);

        self::bindAuditIntercept(CapturePolicy::class, $recorder);

        return $recorder;
    }

    /**
     * Install a {@see RecordingSinkFailureHandler} as the bound
     * {@see SinkFailureHandler} for the duration of the test.
     *
     * See {@see interceptCapturePolicy()} for the design contract.
     */
    public static function interceptSinkFailureHandler(?SinkFailureHandler $delegate = null): RecordingSinkFailureHandler
    {
        $recorder = new RecordingSinkFailureHandler($delegate);

        self::bindAuditIntercept(SinkFailureHandler::class, $recorder);

        return $recorder;
    }

    /**
     * Install a {@see RecordingSwarmAuditSigner} as the bound
     * {@see SwarmAuditSigner} for the duration of the test.
     *
     * See {@see interceptCapturePolicy()} for the design contract. Unlike
     * the other two contracts, no default binding exists for the signer —
     * installing the recorder is what enables signing in the dispatcher
     * during the run.
     */
    public static function interceptSwarmAuditSigner(?SwarmAuditSigner $delegate = null): RecordingSwarmAuditSigner
    {
        $recorder = new RecordingSwarmAuditSigner($delegate);

        self::bindAuditIntercept(SwarmAuditSigner::class, $recorder);

        return $recorder;
    }

    /**
     * Install a {@see RecordingSwarmAuditSink} as the bound
     * {@see SwarmAuditSink} for the duration of the test, so the audit trail a
     * run emits can be asserted with {@see RecordingSwarmAuditSink::assertAuditChain()},
     * {@see RecordingSwarmAuditSink::assertEmittedAudit()}, and
     * {@see RecordingSwarmAuditSink::assertStepCount()}.
     *
     * See {@see interceptCapturePolicy()} for the design contract. The default
     * binding is {@see NoOpSwarmAuditSink};
     * pass a delegate to keep a real sink in the loop behind the recorder.
     */
    public static function interceptSwarmAuditSink(?SwarmAuditSink $delegate = null): RecordingSwarmAuditSink
    {
        $recorder = new RecordingSwarmAuditSink($delegate);

        self::bindAuditIntercept(SwarmAuditSink::class, $recorder);

        return $recorder;
    }

    /**
     * Install a {@see RecordingMemoryCapturePolicy} as the bound
     * {@see MemoryCapturePolicy} for the duration of the test.
     *
     * The returned recorder records every memory-write decision the
     * {@see RedactingMemoryStore} consults
     * (scope, key, context, actor, decision). The optional delegate is
     * forwarded to behind the recording layer so existing policy logic still
     * drives decisions; pass null to record-and-return
     * {@see CaptureDecision::Full} for every write.
     *
     * Unlike {@see interceptCapturePolicy()}, this also flushes the
     * {@see MemoryStore} singleton — the redaction decorator captures its
     * policy at resolve time, so the store must re-resolve to pick up the
     * recorder.
     */
    public static function interceptMemoryCapturePolicy(?MemoryCapturePolicy $delegate = null): RecordingMemoryCapturePolicy
    {
        $recorder = new RecordingMemoryCapturePolicy($delegate);

        $container = Container::getInstance();

        $container->instance(MemoryCapturePolicy::class, $recorder);
        // The decorator captures its policy at MemoryStore resolve time, and the
        // SwarmMemory facade captures the store at its resolve time — flush both
        // so the next memory access rebuilds the chain around the recorder.
        $container->forgetInstance(MemoryStore::class);
        $container->forgetInstance(SwarmMemory::class);

        return $recorder;
    }

    /**
     * Swap the bound contract to the recording decorator and flush the
     * dispatcher singleton so it picks up the new contract on next resolve.
     */
    protected static function bindAuditIntercept(string $abstract, object $recorder): void
    {
        $container = Container::getInstance();

        $container->instance($abstract, $recorder);
        $container->forgetInstance(SwarmAuditDispatcher::class);
    }

    /**
     * Resolve the fake response for the given task.
     *
     * @param  SwarmTaskInput  $task
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

    /**
     * @param  SwarmStructuredSubset|callable  $expected
     */
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

    /**
     * Collect Actor instances from every recorded dispatch that carried a
     * RunContext with a metadata.actor entry.
     *
     * @return array<int, Actor>
     */
    protected function collectRecordedActors(): array
    {
        $actors = [];

        foreach ($this->allRecordedDispatches() as $task) {
            $actor = $this->actorFromTask($task);

            if ($actor !== null) {
                $actors[] = $actor;
            }
        }

        return $actors;
    }

    /**
     * @return array<int, Actor>
     */
    protected function recordedDispatchesMatchingActor(Actor|string|callable $expected): array
    {
        $matches = [];

        foreach ($this->collectRecordedActors() as $actor) {
            if ($this->actorMatches($actor, $expected)) {
                $matches[] = $actor;
            }
        }

        return $matches;
    }

    /**
     * @param  string|array<string, mixed>|RunContext  $task
     */
    protected function actorFromTask(string|array|RunContext $task): ?Actor
    {
        if ($task instanceof RunContext) {
            return $task->actor();
        }

        return null;
    }

    protected function actorMatches(Actor $actor, Actor|string|callable $expected): bool
    {
        if (is_callable($expected)) {
            return (bool) $expected($actor);
        }

        if ($expected instanceof Actor) {
            return $actor->id === $expected->id && $actor->type === $expected->type;
        }

        $reference = Actor::fromAny($expected);

        return $actor->id === $reference->id && $actor->type === $reference->type;
    }

    /**
     * @return array<int, string|array<string, mixed>|RunContext>
     */
    protected function allRecordedDispatches(): array
    {
        return array_merge(
            $this->recorded,
            $this->recordedQueued,
            $this->recordedDurable,
            $this->recordedStreamed,
        );
    }
}
