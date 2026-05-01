<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Concerns;

use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\PersistedRunContextMatcher;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmEventRecorder;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake as SwarmFakeInstance;
use Illuminate\Broadcasting\Channel;
use Illuminate\Container\Container;
use Illuminate\Testing\Assert as PHPUnit;
use ReflectionClass;

trait Runnable
{
    /**
     * Create a new swarm instance for sync or queued execution.
     *
     * When named arguments are provided, the swarm is resolved through the container.
     * Positional arguments may create a direct instance for sync usage.
     * Queueability is enforced by queue(), not by make() itself.
     *
     * @return static|SwarmFakeInstance
     */
    public static function make(mixed ...$arguments): mixed
    {
        if (Container::getInstance()->bound(static::class)) {
            $resolved = Container::getInstance()->make(static::class);
            if ($resolved instanceof SwarmFakeInstance) {
                return $resolved;
            }
        }

        return match (true) {
            $arguments !== [] && ! array_is_list($arguments) => Container::getInstance()->makeWith(static::class, $arguments),
            $arguments !== [] => (new ReflectionClass(static::class))->newInstanceArgs($arguments),
            default => Container::getInstance()->make(static::class),
        };
    }

    /**
     * Invoke the swarm with a given prompt or structured task.
     */
    public function prompt(string|array|RunContext $task): SwarmResponse
    {
        return Container::getInstance()->make(SwarmRunner::class)->run($this, $task);
    }

    /**
     * Run the swarm with the given task.
     *
     * This method is retained as a compatibility alias for prompt().
     */
    public function run(string|array|RunContext $task): SwarmResponse
    {
        return $this->prompt($task);
    }

    /**
     * Stream the swarm, yielding typed stream events for SSE.
     */
    public function stream(string|array|RunContext $task): StreamableSwarmResponse
    {
        return Container::getInstance()->make(SwarmRunner::class)->stream($this, $task);
    }

    /**
     * Broadcast typed stream events for the swarm.
     */
    public function broadcast(string|array|RunContext $task, Channel|array $channels, bool $now = false): StreamableSwarmResponse
    {
        return Container::getInstance()->make(SwarmRunner::class)->broadcast($this, $task, $channels, $now);
    }

    /**
     * Broadcast typed stream events immediately.
     */
    public function broadcastNow(string|array|RunContext $task, Channel|array $channels): StreamableSwarmResponse
    {
        return $this->broadcast($task, $channels, now: true);
    }

    /**
     * Queue the swarm to run in the background.
     */
    public function queue(string|array|RunContext $task): QueuedSwarmResponse
    {
        return Container::getInstance()->make(SwarmRunner::class)->queue($this, $task);
    }

    /**
     * Queue the swarm stream and broadcast each event from the worker.
     */
    public function broadcastOnQueue(string|array|RunContext $task, Channel|array $channels): QueuedSwarmResponse
    {
        return Container::getInstance()->make(SwarmRunner::class)->broadcastOnQueue($this, $task, $channels);
    }

    public function dispatchDurable(string|array|RunContext $task): DurableSwarmResponse
    {
        return Container::getInstance()->make(SwarmRunner::class)->dispatchDurable($this, $task);
    }

    /**
     * Register a fake for this swarm during testing.
     */
    public static function fake(array|callable|null $responses = null): SwarmFakeInstance
    {
        $fake = new SwarmFakeInstance(static::class, $responses);

        Container::getInstance()->instance(static::class, $fake);

        return $fake;
    }

    /**
     * Assert the swarm was prompted with the given task.
     */
    public static function assertPrompted(string|array|callable $task): void
    {
        $resolved = Container::getInstance()->make(static::class);

        PHPUnit::assertInstanceOf(
            SwarmFakeInstance::class,
            $resolved,
            'The expected swarm was not faked before calling assertPrompted().',
        );

        /** @var SwarmFakeInstance $resolved */
        $resolved->assertPrompted($task);
    }

    /**
     * Assert the swarm was never prompted.
     */
    public static function assertNeverPrompted(): void
    {
        $resolved = Container::getInstance()->make(static::class);

        PHPUnit::assertInstanceOf(
            SwarmFakeInstance::class,
            $resolved,
            'The expected swarm was not faked before calling assertNeverPrompted().',
        );

        /** @var SwarmFakeInstance $resolved */
        $resolved->assertNeverPrompted();
    }

    /**
     * Assert the swarm was run with the given task.
     */
    public static function assertRan(string|array|callable $task): void
    {
        $resolved = Container::getInstance()->make(static::class);

        PHPUnit::assertInstanceOf(
            SwarmFakeInstance::class,
            $resolved,
            'The expected swarm was not faked before calling assertRan().',
        );

        /** @var SwarmFakeInstance $resolved */
        $resolved->assertRan($task);
    }

    /**
     * Assert the swarm was never run.
     */
    public static function assertNeverRan(): void
    {
        $resolved = Container::getInstance()->make(static::class);

        PHPUnit::assertInstanceOf(
            SwarmFakeInstance::class,
            $resolved,
            'The expected swarm was not faked before calling assertNeverRan().',
        );

        /** @var SwarmFakeInstance $resolved */
        $resolved->assertNeverRan();
    }

    /**
     * Assert the swarm was queued with the given task.
     */
    public static function assertQueued(string|array|callable $task): void
    {
        $resolved = Container::getInstance()->make(static::class);

        PHPUnit::assertInstanceOf(
            SwarmFakeInstance::class,
            $resolved,
            'The expected swarm was not faked before calling assertQueued().',
        );

        /** @var SwarmFakeInstance $resolved */
        $resolved->assertQueued($task);
    }

    /**
     * Assert the swarm was never queued.
     */
    public static function assertNeverQueued(): void
    {
        $resolved = Container::getInstance()->make(static::class);

        PHPUnit::assertInstanceOf(
            SwarmFakeInstance::class,
            $resolved,
            'The expected swarm was not faked before calling assertNeverQueued().',
        );

        /** @var SwarmFakeInstance $resolved */
        $resolved->assertNeverQueued();
    }

    public static function assertDispatchedDurably(string|array|callable $task): void
    {
        $resolved = Container::getInstance()->make(static::class);

        PHPUnit::assertInstanceOf(
            SwarmFakeInstance::class,
            $resolved,
            'The expected swarm was not faked before calling assertDispatchedDurably().',
        );

        /** @var SwarmFakeInstance $resolved */
        $resolved->assertDispatchedDurably($task);
    }

    public static function assertNeverDispatchedDurably(): void
    {
        $resolved = Container::getInstance()->make(static::class);

        PHPUnit::assertInstanceOf(
            SwarmFakeInstance::class,
            $resolved,
            'The expected swarm was not faked before calling assertNeverDispatchedDurably().',
        );

        /** @var SwarmFakeInstance $resolved */
        $resolved->assertNeverDispatchedDurably();
    }

    public static function assertDurableSignalled(string|callable $name): void
    {
        $resolved = static::resolvedFakeForDurableAssertion('assertDurableSignalled');
        $resolved->assertDurableSignalled($name);
    }

    public static function assertDurableWaited(string|callable $name): void
    {
        $resolved = static::resolvedFakeForDurableAssertion('assertDurableWaited');
        $resolved->assertDurableWaited($name);
    }

    public static function assertDurableProgressRecorded(array|callable $progress): void
    {
        $resolved = static::resolvedFakeForDurableAssertion('assertDurableProgressRecorded');
        $resolved->assertDurableProgressRecorded($progress);
    }

    public static function assertDurableLabels(array|callable $labels): void
    {
        $resolved = static::resolvedFakeForDurableAssertion('assertDurableLabels');
        $resolved->assertDurableLabels($labels);
    }

    public static function assertDurableDetails(array|callable $details): void
    {
        $resolved = static::resolvedFakeForDurableAssertion('assertDurableDetails');
        $resolved->assertDurableDetails($details);
    }

    public static function assertDurableRetryScheduled(array|callable $policy): void
    {
        $resolved = static::resolvedFakeForDurableAssertion('assertDurableRetryScheduled');
        $resolved->assertDurableRetryScheduled($policy);
    }

    public static function assertDurableChildSwarmDispatched(string|callable $childSwarmClass): void
    {
        $resolved = static::resolvedFakeForDurableAssertion('assertDurableChildSwarmDispatched');
        $resolved->assertDurableChildSwarmDispatched($childSwarmClass);
    }

    protected static function resolvedFakeForDurableAssertion(string $assertion): SwarmFakeInstance
    {
        $resolved = Container::getInstance()->make(static::class);

        PHPUnit::assertInstanceOf(
            SwarmFakeInstance::class,
            $resolved,
            "The expected swarm was not faked before calling {$assertion}().",
        );

        return $resolved;
    }

    /**
     * Assert the swarm was streamed with the given task.
     */
    public static function assertStreamed(string|array|callable $task): void
    {
        $resolved = Container::getInstance()->make(static::class);

        PHPUnit::assertInstanceOf(
            SwarmFakeInstance::class,
            $resolved,
            'The expected swarm was not faked before calling assertStreamed().',
        );

        /** @var SwarmFakeInstance $resolved */
        $resolved->assertStreamed($task);
    }

    /**
     * Assert the swarm was never streamed.
     */
    public static function assertNeverStreamed(): void
    {
        $resolved = Container::getInstance()->make(static::class);

        PHPUnit::assertInstanceOf(
            SwarmFakeInstance::class,
            $resolved,
            'The expected swarm was not faked before calling assertNeverStreamed().',
        );

        /** @var SwarmFakeInstance $resolved */
        $resolved->assertNeverStreamed();
    }

    public static function assertPersisted(string|array|callable|null $run = null, ?string $status = null): void
    {
        $history = Container::getInstance()->make(SwarmHistory::class);

        if (is_string($run)) {
            $record = $history->find($run);

            PHPUnit::assertNotNull(
                $record,
                'No persisted run was found with run ID ['.$run.'].',
            );

            PHPUnit::assertSame(
                static::class,
                $record['swarm_class'] ?? null,
                'The persisted run ['.$run.'] does not belong to swarm ['.static::class.'].',
            );

            if ($status !== null) {
                PHPUnit::assertSame(
                    $status,
                    $record['status'] ?? null,
                    'The persisted run ['.$run.'] for swarm ['.static::class.'] does not have status ['.$status.'].',
                );
            }

            return;
        }

        if (is_callable($run)) {
            foreach ($history->findMatching(static::class, $status) as $record) {
                if ((bool) $run($record)) {
                    return;
                }
            }

            PHPUnit::fail('No persisted run for swarm ['.static::class.'] matched the expected assertion.');
        }

        if (is_array($run)) {
            foreach ($history->findMatching(static::class, $status, $run) as $record) {
                if (PersistedRunContextMatcher::matchesRecord($run, $record)) {
                    return;
                }
            }

            PHPUnit::fail('No persisted run for swarm ['.static::class.'] matched the expected task/context subset.');
        }

        foreach ($history->findMatching(static::class, $status) as $record) {
            if (is_array($record)) {
                return;
            }
        }

        PHPUnit::fail(
            'No persisted runs were found for swarm ['.static::class.']'.($status !== null ? " with status [{$status}]." : '.'),
        );
    }

    public static function assertEventFired(string $eventClass, ?callable $callback = null): void
    {
        /** @var SwarmEventRecorder $recorder */
        $recorder = Container::getInstance()->make(SwarmEventRecorder::class);

        PHPUnit::assertTrue(
            $recorder->isActive(),
            'Swarm event recording is only available in tests where the recorder has been activated.',
        );

        $events = $recorder->eventsFor(static::class, $eventClass);

        if ($callback !== null) {
            PHPUnit::assertTrue(
                collect($events)->contains(fn (object $event): bool => (bool) $callback($event)),
                "The event [{$eventClass}] was not fired for swarm [".static::class.'] with the expected payload.',
            );

            return;
        }

        PHPUnit::assertNotEmpty(
            $events,
            "The event [{$eventClass}] was not fired for swarm [".static::class.'].',
        );
    }
}
