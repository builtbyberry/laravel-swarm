<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Generator;
use Illuminate\Container\Container;
use Illuminate\Testing\Assert as PHPUnit;
use Laravel\Ai\FakePendingDispatch;

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
    public function run(string|array|RunContext $task): SwarmResponse
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
            Container::getInstance()->make(DurableSwarmManager::class),
            'fake-run-id',
        );
    }

    /**
     * Intercept a stream call and record it.
     *
     * @return Generator<int, array<string, string>, mixed, void>
     */
    public function stream(string|array|RunContext $task): Generator
    {
        $this->recordedStreamed[] = $task;

        $output = $this->resolveResponse($task);

        return (function () use ($output): Generator {
            yield ['event' => 'step', 'agent' => 'SwarmFake', 'status' => 'running'];
            yield ['event' => 'token', 'token' => $output];
            yield ['event' => 'step', 'agent' => 'SwarmFake', 'status' => 'done'];
        })();
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
