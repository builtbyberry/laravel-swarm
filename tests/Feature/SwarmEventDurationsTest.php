<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalMultiRouteSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStreamingFailureSwarm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config()->set('database.default', 'testing');

    FakeResearcher::fake(['research-out']);
    FakeHierarchicalCoordinator::fake([[
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'next' => 'editor_node',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-task',
            ],
        ],
    ]]);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('sequential swarm completion and step events include positive integer durations', function () {
    Event::fake();

    FakeSequentialSwarm::make()->run('duration-task');

    Event::assertDispatched(SwarmCompleted::class, function (SwarmCompleted $event): bool {
        return $event->topology === 'sequential'
            && $event->durationMs > 0;
    });

    Event::assertDispatched(SwarmStepCompleted::class, function (SwarmStepCompleted $event): bool {
        return $event->topology === 'sequential'
            && $event->durationMs > 0;
    });
});

test('parallel swarm step events include positive integer durations', function () {
    Event::fake();

    FakeParallelSwarm::make()->run('parallel-task');

    Event::assertDispatched(SwarmStepCompleted::class, function (SwarmStepCompleted $event): bool {
        return $event->topology === 'parallel'
            && $event->durationMs > 0;
    });
});

test('hierarchical swarm step events include positive integer durations', function () {
    Event::fake();

    FakeHierarchicalMultiRouteSwarm::make()->run('hierarchical-task');

    Event::assertDispatched(SwarmStepCompleted::class, function (SwarmStepCompleted $event): bool {
        return $event->topology === 'hierarchical'
            && $event->durationMs > 0;
    });
});

test('streamed swarms include positive integer durations on completion and failure paths', function () {
    $completedEvent = null;

    app('events')->listen(SwarmCompleted::class, function (SwarmCompleted $event) use (&$completedEvent): void {
        $completedEvent = $event;
    });

    iterator_to_array(FakeSequentialSwarm::make()->stream('stream-task'));

    expect($completedEvent)->toBeInstanceOf(SwarmCompleted::class)
        ->and($completedEvent->topology)->toBe('sequential')
        ->and($completedEvent->durationMs)->toBeGreaterThan(0);

    $failedEvent = null;

    app('events')->listen(SwarmFailed::class, function (SwarmFailed $event) use (&$failedEvent): void {
        $failedEvent = $event;
    });

    expect(fn () => iterator_to_array(FakeStreamingFailureSwarm::make()->stream('stream-task')))
        ->toThrow(RuntimeException::class, 'Final agent stream failed.');

    expect($failedEvent)->toBeInstanceOf(SwarmFailed::class)
        ->and($failedEvent->topology)->toBe('sequential')
        ->and($failedEvent->durationMs)->toBeGreaterThan(0);
});

test('queued swarms include positive integer durations', function () {
    Event::fake();

    app(SwarmRunner::class)->runQueued(FakeSequentialSwarm::make(), 'queued-task');

    Event::assertDispatched(SwarmCompleted::class, fn (SwarmCompleted $event) => $event->topology === 'sequential'
        && $event->durationMs > 0);
});

test('queued swarms do not emit failed events after lease loss', function () {
    config()->set('swarm.persistence.driver', 'database');
    Event::fake();

    $context = RunContext::from('queued-task', 'duration-lease-loss-run-id');

    DB::table('swarm_run_histories')->insert([
        'run_id' => 'duration-lease-loss-run-id',
        'swarm_class' => FailingQueuedSwarm::class,
        'topology' => 'sequential',
        'status' => 'running',
        'context' => json_encode($context->toArray()),
        'metadata' => json_encode(['swarm_class' => FailingQueuedSwarm::class, 'topology' => 'sequential']),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => null,
        'expires_at' => now()->addHour(),
        'execution_token' => 'expired-token',
        'leased_until' => now()->subMinute(),
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    try {
        app(SwarmRunner::class)->runQueued(FailingQueuedSwarm::make(), $context);
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Queued swarm failed.');
    }

    DB::table('swarm_run_histories')
        ->where('run_id', 'duration-lease-loss-run-id')
        ->update([
            'execution_token' => 'replacement-token',
            'leased_until' => now()->addMinutes(5),
            'updated_at' => now(),
        ]);

    Event::fake();

    app(SwarmRunner::class)->runQueued(FailingQueuedSwarm::make(), $context);

    Event::assertNotDispatched(SwarmFailed::class, fn (SwarmFailed $event) => $event->runId === 'duration-lease-loss-run-id');
});

test('failed swarm events include positive non zero integer durations', function () {
    Event::fake();

    expect(fn () => FailingQueuedSwarm::make()->run('failed-task'))
        ->toThrow(RuntimeException::class, 'Queued swarm failed.');

    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $event) => $event->durationMs > 0);
});
