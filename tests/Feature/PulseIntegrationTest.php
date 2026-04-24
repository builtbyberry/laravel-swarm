<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Pulse\Livewire\SwarmRuns as SwarmRunsCard;
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmRuns as SwarmRunsRecorder;
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmStepDurations;
use BuiltByBerry\LaravelSwarm\Pulse\Support\SwarmPulseKey;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Jobs\NoOpQueuedJob;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalMultiRouteSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStreamingFailureSwarm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Pulse\Facades\Pulse as PulseFacade;
use Laravel\Pulse\Pulse;
use Livewire\Livewire;

function preventPulseTestRedispatch(object $response): void
{
    $dispatchableProperty = new ReflectionProperty($response, 'dispatchable');
    $dispatchableProperty->setAccessible(true);

    $dispatchable = $dispatchableProperty->getValue($response);

    $jobProperty = new ReflectionProperty($dispatchable, 'job');
    $jobProperty->setAccessible(true);
    $jobProperty->setValue($dispatchable, new NoOpQueuedJob);
}

beforeEach(function () {
    config()->set('database.default', 'testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Artisan::call('migrate', [
        '--database' => 'testing',
        '--path' => __DIR__.'/../../vendor/laravel/pulse/database/migrations',
        '--realpath' => true,
    ]);

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

    PulseFacade::purge();
    PulseFacade::flush();
    app(Pulse::class)->register([
        SwarmRunsRecorder::class => ['enabled' => true],
        SwarmStepDurations::class => ['enabled' => true],
    ]);
});

test('sequential swarm completion and step events include positive integer durations', function () {
    Event::fake();

    FakeSequentialSwarm::make()->run('pulse-task');

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

    $context = RunContext::from('queued-task', 'pulse-lease-loss-run-id');

    DB::table('swarm_run_histories')->insert([
        'run_id' => 'pulse-lease-loss-run-id',
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
        ->where('run_id', 'pulse-lease-loss-run-id')
        ->update([
            'execution_token' => 'replacement-token',
            'leased_until' => now()->addMinutes(5),
            'updated_at' => now(),
        ]);

    Event::fake();

    app(SwarmRunner::class)->runQueued(FailingQueuedSwarm::make(), $context);

    Event::assertNotDispatched(SwarmFailed::class, fn (SwarmFailed $event) => $event->runId === 'pulse-lease-loss-run-id');
});

test('failed swarm events include positive non zero integer durations', function () {
    Event::fake();

    expect(fn () => FailingQueuedSwarm::make()->run('failed-task'))
        ->toThrow(RuntimeException::class, 'Queued swarm failed.');

    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $event) => $event->durationMs > 0);
});

test('pulse recorders store stable swarm entry keys', function () {
    FakeSequentialSwarm::make()->run('pulse-task');

    PulseFacade::ingest();

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_run')->pluck('key')->all())
        ->toContain('swarm_run|'.FakeSequentialSwarm::class.'|sequential|completed');

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_run_duration')->pluck('key')->all())
        ->toContain('swarm_run_duration|'.FakeSequentialSwarm::class.'|sequential');

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_run_duration_total')->pluck('key')->all())
        ->toContain(FakeSequentialSwarm::class);

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_run_total')->pluck('key')->all())
        ->toContain(FakeSequentialSwarm::class);

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_run_duration_total_ms')->pluck('key')->all())
        ->toContain(FakeSequentialSwarm::class);

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_run_duration_samples')->pluck('key')->all())
        ->toContain(FakeSequentialSwarm::class);

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_step_duration')->pluck('key')->all())
        ->toContain('swarm_step_duration|'.FakeSequentialSwarm::class.'|sequential|'.FakeWriter::class);
});

test('swarm runs card keeps per swarm metrics accurate when raw pulse keys exceed the old regrouping limit', function () {
    $timestamp = now()->getTimestamp();

    for ($i = 1; $i <= 600; $i++) {
        PulseFacade::record('swarm_run_total', FakeSequentialSwarm::class, value: 1, timestamp: $timestamp)->sum();
        PulseFacade::record('swarm_topology_sequential', FakeSequentialSwarm::class, value: 1, timestamp: $timestamp)->sum();
        PulseFacade::record('swarm_run_duration_total', FakeSequentialSwarm::class, value: 10, timestamp: $timestamp)->avg()->count();
        PulseFacade::record('swarm_run_duration_total_ms', FakeSequentialSwarm::class, value: 10, timestamp: $timestamp)->sum();
        PulseFacade::record('swarm_run_duration_samples', FakeSequentialSwarm::class, value: 1, timestamp: $timestamp)->sum();
        PulseFacade::record('swarm_run', SwarmPulseKey::runStatus(FakeSequentialSwarm::class, 'sequential', 'completed'), timestamp: $timestamp)->count()->onlyBuckets();
        PulseFacade::record('swarm_run_duration', SwarmPulseKey::runDuration(FakeSequentialSwarm::class, 'sequential'), value: 10, timestamp: $timestamp)->avg()->count()->onlyBuckets();
    }

    PulseFacade::record('swarm_run_total', FakeSequentialSwarm::class, value: 1, timestamp: $timestamp)->sum();
    PulseFacade::record('swarm_run_failed', FakeSequentialSwarm::class, value: 1, timestamp: $timestamp)->sum();
    PulseFacade::record('swarm_topology_parallel', FakeSequentialSwarm::class, value: 1, timestamp: $timestamp)->sum();
    PulseFacade::record('swarm_run_duration_total', FakeSequentialSwarm::class, value: 1000, timestamp: $timestamp)->avg()->count();
    PulseFacade::record('swarm_run_duration_total_ms', FakeSequentialSwarm::class, value: 1000, timestamp: $timestamp)->sum();
    PulseFacade::record('swarm_run_duration_samples', FakeSequentialSwarm::class, value: 1, timestamp: $timestamp)->sum();
    PulseFacade::record('swarm_run', SwarmPulseKey::runStatus(FakeSequentialSwarm::class, 'parallel', 'failed'), timestamp: $timestamp)->count()->onlyBuckets();
    PulseFacade::record('swarm_run_duration', SwarmPulseKey::runDuration(FakeSequentialSwarm::class, 'parallel'), value: 1000, timestamp: $timestamp)->avg()->count()->onlyBuckets();

    for ($i = 1; $i <= 500; $i++) {
        $swarmClass = "App\\Ai\\Swarms\\DistractorSwarm{$i}";

        for ($count = 1; $count <= 2; $count++) {
            PulseFacade::record('swarm_run', SwarmPulseKey::runStatus($swarmClass, 'sequential', 'completed'), timestamp: $timestamp)->count()->onlyBuckets();
            PulseFacade::record('swarm_run_duration', SwarmPulseKey::runDuration($swarmClass, 'sequential'), value: 50, timestamp: $timestamp)->avg()->count()->onlyBuckets();
        }
    }

    PulseFacade::ingest();

    $card = new class extends SwarmRunsCard
    {
        public function snapshot(): Collection
        {
            return $this->resolveRuns();
        }
    };

    $run = $card->snapshot()->firstWhere('swarmClass', FakeSequentialSwarm::class);

    expect($run)->not()->toBeNull()
        ->and($run->totalRuns)->toBe(601)
        ->and($run->failures)->toBe(1)
        ->and($run->failureRate)->toBe(0.2)
        ->and($run->averageRunDurationMs)->toBe(12)
        ->and($run->topologyMix->mapWithKeys(fn (object $topology) => [$topology->topology => $topology->count])->all())
        ->toBe(['sequential' => 600, 'parallel' => 1]);
});

test('pulse cards render through the registered livewire aliases', function () {
    FakeSequentialSwarm::make()->run('pulse-task');

    PulseFacade::ingest();

    Livewire::withoutLazyLoading();

    Livewire::test('swarm.runs')
        ->assertSee('Swarm Runs');

    Livewire::withoutLazyLoading();

    Livewire::test('swarm.steps')
        ->assertSee('Swarm Steps');
});

test('pulse recorders capture failed swarm runs with the failed status key', function () {
    expect(fn () => FailingQueuedSwarm::make()->run('failed-task'))
        ->toThrow(RuntimeException::class, 'Queued swarm failed.');

    PulseFacade::ingest();

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_run')->pluck('key')->all())
        ->toContain('swarm_run|'.FailingQueuedSwarm::class.'|sequential|failed');
});
