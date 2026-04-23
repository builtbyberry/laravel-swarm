<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Concerns;

use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake as SwarmFakeInstance;
use Generator;
use Illuminate\Container\Container;
use Illuminate\Testing\Assert as PHPUnit;

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
        return match (true) {
            $arguments !== [] && ! array_is_list($arguments) => Container::getInstance()->makeWith(static::class, $arguments),
            $arguments !== [] => new static(...$arguments),
            default => Container::getInstance()->make(static::class),
        };
    }

    /**
     * Run the swarm with the given task.
     */
    public function run(string $task): SwarmResponse
    {
        return Container::getInstance()->make(SwarmRunner::class)->run($this, $task);
    }

    /**
     * Stream the swarm, yielding step and token events for SSE.
     *
     * @return Generator<int, array<string, string>, mixed, void>
     */
    public function stream(string $task): Generator
    {
        return Container::getInstance()->make(SwarmRunner::class)->stream($this, $task);
    }

    /**
     * Queue the swarm to run in the background.
     */
    public function queue(string $task): QueuedSwarmResponse
    {
        return Container::getInstance()->make(SwarmRunner::class)->queue($this, $task);
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
     * Assert the swarm was run with the given task.
     */
    public static function assertRan(string|callable $task): void
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
    public static function assertQueued(string|callable $task): void
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

    /**
     * Assert the swarm was streamed with the given task.
     */
    public static function assertStreamed(string|callable $task): void
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
}
