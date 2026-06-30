<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Enums\GrowthPolicy;
use BuiltByBerry\LaravelSwarm\Exceptions\ContextBudgetExceededException;
use BuiltByBerry\LaravelSwarm\Runners\SwarmAttributeResolver;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeIgnoreGrowthSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRefuseGrowthSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamConcurrentSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamSequentialSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

function bindRecordingTelemetrySink(): RecordingSwarmTelemetrySink
{
    $sink = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $sink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);

    return $sink;
}

// --- Resolution: author attribute vs operator config ----------------------

test('the author attribute overrides the configured default policy', function () {
    config()->set('swarm.context_growth.policy', 'ignore');

    $resolver = app(SwarmAttributeResolver::class);

    expect($resolver->resolveGrowthPolicy(new FakeRefuseGrowthSwarm))->toBe(GrowthPolicy::Refuse)
        ->and($resolver->resolveGrowthPolicy(new FakeSequentialSwarm))->toBe(GrowthPolicy::Ignore);
});

test('an absent attribute falls back to the configured default', function () {
    config()->set('swarm.context_growth.policy', 'backpressure');

    expect(app(SwarmAttributeResolver::class)->resolveGrowthPolicy(new FakeSequentialSwarm))
        ->toBe(GrowthPolicy::Backpressure);
});

test('an unrecognised configured policy resolves to the framework default, never throwing', function () {
    config()->set('swarm.context_growth.policy', 'detonate');

    expect(app(SwarmAttributeResolver::class)->resolveGrowthPolicy(new FakeSequentialSwarm))
        ->toBe(GrowthPolicy::DegradeToCold);
});

// --- End-to-end ladder behaviour ------------------------------------------

test('a refuse-policy swarm aborts the stream loud once it exceeds the budget', function () {
    config()->set('swarm.context_growth.budget_events', 4);

    expect(fn () => iterator_to_array(FakeRefuseGrowthSwarm::make()->stream('task')))
        ->toThrow(ContextBudgetExceededException::class);
});

test('the operator hard cap clamps an author ignore policy into a refusal', function () {
    config()->set('swarm.context_growth.hard_cap_events', 4);

    expect(fn () => iterator_to_array(FakeIgnoreGrowthSwarm::make()->stream('task')))
        ->toThrow(ContextBudgetExceededException::class, 'hard cap');
});

test('a degrade-to-cold policy does not dispatch compaction for a live stream and the run completes', function () {
    Queue::fake();
    config()->set('swarm.context_growth.policy', 'degrade_to_cold');
    config()->set('swarm.context_growth.budget_events', 4);

    $events = iterator_to_array(FakeSequentialSwarm::make()->stream('task'));

    // A live (non-durable) stream() run has no compaction lease anchor, so the
    // degrade-to-cold rung warns instead of dispatching a job that would no-op
    // forever; the hot log is bounded by swarm:prune (TTL). The run still completes.
    expect(end($events)->type())->toBe('swarm_stream_end');
    Queue::assertNothingPushed();
});

test('a warn policy attributes the action in telemetry and the run completes', function () {
    Queue::fake();
    config()->set('swarm.context_growth.policy', 'warn');
    config()->set('swarm.context_growth.budget_events', 4);
    $sink = bindRecordingTelemetrySink();

    $events = iterator_to_array(FakeSequentialSwarm::make()->stream('task'));

    expect(end($events)->type())->toBe('swarm_stream_end');

    $actions = $sink->recordsForCategory('context_growth.action');
    expect($actions)->not->toBeEmpty()
        ->and($actions[0]['action'])->toBe('warn')
        ->and($actions[0]['declared_policy'])->toBe('warn')
        ->and($actions[0]['trigger'])->toBe('budget');

    Queue::assertNothingPushed();
});

test('the policy is inert when the operator supplies no budget or hard cap', function () {
    Queue::fake();
    config()->set('swarm.context_growth.policy', 'refuse'); // would refuse IF a budget existed
    $sink = bindRecordingTelemetrySink();

    $events = iterator_to_array(FakeSequentialSwarm::make()->stream('task'));

    expect(end($events)->type())->toBe('swarm_stream_end')
        ->and($sink->recordsForCategory('context_growth.action'))->toBeEmpty();
    Queue::assertNothingPushed();
});

test('the governor is wired into the hierarchical plan-walk seam', function () {
    config()->set('swarm.context_growth.policy', 'refuse');
    config()->set('swarm.context_growth.budget_events', 2);

    expect(fn () => iterator_to_array(FakeStaticHierarchicalStreamSequentialSwarm::make()->stream('task')))
        ->toThrow(ContextBudgetExceededException::class);
});

test('parallel-branch step boundaries are governed in concurrent mode', function () {
    config()->set('swarm.static_hierarchical.stream_parallel_branches', 'concurrent');
    config()->set('swarm.context_growth.policy', 'refuse');
    config()->set('swarm.context_growth.budget_events', 2);

    // Growth driven purely by a parallel fan-out must still trip refuse — the
    // branch SwarmStepEnd events are step boundaries too (regression for F1).
    expect(fn () => iterator_to_array(FakeStaticHierarchicalStreamConcurrentSwarm::make()->stream('task')))
        ->toThrow(ContextBudgetExceededException::class);
});

test('parallel-branch step boundaries are governed in sequential-branch mode', function () {
    config()->set('swarm.static_hierarchical.stream_parallel_branches', 'sequential');
    config()->set('swarm.context_growth.policy', 'refuse');
    config()->set('swarm.context_growth.budget_events', 2);

    expect(fn () => iterator_to_array(FakeStaticHierarchicalStreamConcurrentSwarm::make()->stream('task')))
        ->toThrow(ContextBudgetExceededException::class);
});

test('a budget refusal yields a stream-error event and audits run.failed with the typed class', function () {
    config()->set('swarm.context_growth.budget_events', 4); // FakeRefuseGrowthSwarm declares Refuse

    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);

    $events = [];
    $threw = false;
    try {
        foreach (FakeRefuseGrowthSwarm::make()->stream('task') as $event) {
            $events[] = $event;
        }
    } catch (ContextBudgetExceededException) {
        $threw = true;
    }

    expect($threw)->toBeTrue()
        ->and(collect($events)->contains(fn ($e): bool => $e instanceof SwarmStreamError))->toBeTrue();

    $failed = $sink->recordsForCategory('run.failed');
    expect($failed)->not->toBeEmpty()
        ->and($failed[0]['exception_class'])->toBe(ContextBudgetExceededException::class);
});
