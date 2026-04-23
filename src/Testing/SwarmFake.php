<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use Generator;
use Illuminate\Testing\Assert as PHPUnit;
use Laravel\Ai\FakePendingDispatch;

class SwarmFake implements Swarm
{
    /**
     * @var array<int, string>
     */
    protected array $recorded = [];

    /**
     * @var array<int, string>
     */
    protected array $recordedQueued = [];

    /**
     * @var array<int, string>
     */
    protected array $recordedStreamed = [];

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
     * Intercept a run call and record it.
     */
    public function run(string $task): SwarmResponse
    {
        $this->recorded[] = $task;

        $output = $this->resolveResponse($task);

        return new SwarmResponse(
            output: $output,
            metadata: ['run_id' => 'fake-run-id'],
        );
    }

    /**
     * Intercept a queue call and record it.
     */
    public function queue(string $task): QueuedSwarmResponse
    {
        $this->recordedQueued[] = $task;

        return new QueuedSwarmResponse(new FakePendingDispatch, 'fake-run-id');
    }

    /**
     * Intercept a stream call and record it.
     *
     * @return Generator<int, array<string, string>, mixed, void>
     */
    public function stream(string $task): Generator
    {
        $this->recordedStreamed[] = $task;

        $output = $this->resolveResponse($task);

        return (function () use ($output): Generator {
            yield ['event' => 'token', 'token' => $output];
        })();
    }

    /**
     * Assert the swarm was run with the given task.
     */
    public function assertRan(string|callable $task): void
    {
        if (is_callable($task)) {
            PHPUnit::assertTrue(
                collect($this->recorded)->contains(fn ($recorded) => $task($recorded)),
                "The swarm [{$this->swarmClass}] was not run with the expected task.",
            );

            return;
        }

        PHPUnit::assertContains(
            $task,
            $this->recorded,
            "The swarm [{$this->swarmClass}] was not run with task: [{$task}].",
        );
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
    public function assertQueued(string|callable $task): void
    {
        if (is_callable($task)) {
            PHPUnit::assertTrue(
                collect($this->recordedQueued)->contains(fn ($recorded) => $task($recorded)),
                "The swarm [{$this->swarmClass}] was not queued with the expected task.",
            );

            return;
        }

        PHPUnit::assertContains(
            $task,
            $this->recordedQueued,
            "The swarm [{$this->swarmClass}] was not queued with task: [{$task}].",
        );
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
     * Assert the swarm was streamed with the given task.
     */
    public function assertStreamed(string|callable $task): void
    {
        if (is_callable($task)) {
            PHPUnit::assertTrue(
                collect($this->recordedStreamed)->contains(fn ($recorded) => $task($recorded)),
                "The swarm [{$this->swarmClass}] was not streamed with the expected task.",
            );

            return;
        }

        PHPUnit::assertContains(
            $task,
            $this->recordedStreamed,
            "The swarm [{$this->swarmClass}] was not streamed with task: [{$task}].",
        );
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
    protected function resolveResponse(string $task): string
    {
        if (is_callable($this->responses)) {
            return ($this->responses)($task);
        }

        if (is_array($this->responses) && $this->responses !== []) {
            return array_shift($this->responses);
        }

        return "Fake response for swarm [{$this->swarmClass}].";
    }
}
