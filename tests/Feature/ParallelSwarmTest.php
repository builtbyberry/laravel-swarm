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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\EmptyParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use Illuminate\Support\Facades\Event;

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
