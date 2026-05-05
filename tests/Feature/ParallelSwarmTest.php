<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\UnresolvableParallelAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\EmptyParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SerializationBoundaryParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\UnresolvableParallelSwarm;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Facades\Event;
use Laravel\SerializableClosure\SerializableClosure;

beforeEach(function () {
    FakeResearcher::fake(['parallel-a']);
    FakeWriter::fake(['parallel-b']);
    FakeEditor::fake(['parallel-c']);
});

test('parallel swarm runs each agent with the original task', function () {
    $task = 'shared-task';
    $response = FakeParallelSwarm::make()->run($task);

    FakeResearcher::assertPrompted($task);
    FakeWriter::assertPrompted($task);
    FakeEditor::assertPrompted($task);

    expect($response->steps)->toHaveCount(3);

    foreach ($response->steps as $step) {
        expect($step->input)->toBe($task);
    }

    expect((string) $response)->toContain('parallel-a');
    expect((string) $response)->toContain('parallel-b');
    expect((string) $response)->toContain('parallel-c');
});

test('parallel swarm rejects empty agent lists', function () {
    expect(fn () => EmptyParallelSwarm::make()->run('shared-task'))
        ->toThrow(SwarmException::class, 'EmptyParallelSwarm: swarm has no agents. Add at least one agent to agents().');
});

test('parallel swarm agents must be container resolvable for concurrency workers', function () {
    expect(fn () => UnresolvableParallelSwarm::make()->run('shared-task'))
        ->toThrow(SwarmException::class, UnresolvableParallelSwarm::class.': parallel agent ['.UnresolvableParallelAgent::class.'] must be container-resolvable because Laravel Concurrency serializes worker callbacks.');
});

test('parallel swarm crosses the concurrency serialization boundary without agent instance state', function () {
    app()->instance(ConcurrencyManager::class, new class(app()) extends ConcurrencyManager
    {
        public function driver($name = null): Driver
        {
            return new class implements Driver
            {
                public function run(Closure|array $tasks): array
                {
                    $results = [];

                    foreach ($tasks as $key => $task) {
                        $serializedTask = serialize(new SerializableClosure($task));

                        /** @var SerializableClosure $roundTrippedTask */
                        $roundTrippedTask = unserialize($serializedTask);

                        $results[$key] = $roundTrippedTask();
                    }

                    return $results;
                }

                public function defer(Closure|array $tasks): DeferredCallback
                {
                    throw new RuntimeException('Not supported.');
                }
            };
        }
    });

    $response = SerializationBoundaryParallelSwarm::make()->run('shared-task');

    expect($response->steps)->toHaveCount(2)
        ->and((string) $response)->toContain('serialization-boundary:shared-task');
});

test('parallel swarm fails when concurrency returns a sparse result set', function () {
    app()->instance(ConcurrencyManager::class, new class(app()) extends ConcurrencyManager
    {
        public function driver($name = null): Driver
        {
            return new class implements Driver
            {
                public function run(Closure|array $tasks): array
                {
                    return [];
                }

                public function defer(Closure|array $tasks): DeferredCallback
                {
                    throw new RuntimeException('Not supported.');
                }
            };
        }
    });

    expect(fn () => FakeParallelSwarm::make()->run('shared-task'))
        ->toThrow(SwarmException::class, FakeParallelSwarm::class.': parallel execution did not return a result for agent index [0].');
});

test('parallel swarm records artifacts and metadata', function () {
    $response = FakeParallelSwarm::make()->run('shared-task');

    expect($response->metadata['topology'])->toBe('parallel');
    expect($response->artifacts)->toHaveCount(3);
    expect($response->steps[0]->artifacts)->toHaveCount(1);
    expect($response->steps[0]->artifacts[0]->name)->toBe('agent_output');
});

test('parallel swarm dispatches lifecycle events', function () {
    Event::fake();

    $response = FakeParallelSwarm::make()->run('shared-task');

    Event::assertDispatched(SwarmStarted::class, fn (SwarmStarted $event) => $event->runId === $response->metadata['run_id']
        && $event->input === 'shared-task'
        && $event->topology === 'parallel'
        && $event->executionMode === 'run');
    Event::assertDispatchedTimes(SwarmStepStarted::class, 3);
    Event::assertDispatchedTimes(SwarmStepCompleted::class, 3);
    Event::assertDispatched(SwarmCompleted::class, fn (SwarmCompleted $event) => $event->runId === $response->metadata['run_id']
        && $event->output === $response->output
        && $event->metadata['topology'] === 'parallel');
});
