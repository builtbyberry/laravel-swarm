<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksInputWhenMatches;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksOutputWhenContains;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksStepWhenIndex;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\GuardrailContainer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.guardrails.input', []);
    config()->set('swarm.guardrails.step', []);
    config()->set('swarm.guardrails.output', []);
    config()->set('swarm.guardrails.child_inheritance', 'own_and_global');
    config()->set('swarm.guardrails.parallel_failure_policy', 'existing');
    config()->set('swarm.capture.inputs', true);
    config()->set('swarm.capture.outputs', true);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    GuardrailContainer::refresh($this->app);
});

test('input guardrail runs before swarm started and dispatches swarm failed', function () {
    Event::fake();

    config()->set('swarm.guardrails.input', [BlocksInputWhenMatches::class]);
    $this->app->bind(BlocksInputWhenMatches::class, fn () => new BlocksInputWhenMatches('block-input-token'));
    GuardrailContainer::refresh($this->app);

    expect(fn () => FakeSequentialSwarm::make()->prompt('block-input-token'))
        ->toThrow(GuardrailViolation::class);

    Event::assertNotDispatched(SwarmStarted::class);
    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $e): bool => $e->exceptionClass === GuardrailViolation::class);
});

test('input guardrail persists preflight failure row with guardrail exception class', function () {
    config()->set('swarm.guardrails.input', [BlocksInputWhenMatches::class]);
    $this->app->bind(BlocksInputWhenMatches::class, fn () => new BlocksInputWhenMatches('blocked'));
    GuardrailContainer::refresh($this->app);

    $runId = 'guardrail-preflight-'.uniqid('', true);

    expect(fn () => FakeSequentialSwarm::make()->prompt(RunContext::from('blocked', $runId)))
        ->toThrow(GuardrailViolation::class);

    $record = app(RunHistoryStore::class)->find($runId);
    expect($record)->not->toBeNull()
        ->and($record['status'])->toBe('failed')
        ->and(($record['error']['class'] ?? null))->toBe(GuardrailViolation::class);
});

test('output guardrail fails after agents run and before completed event', function () {
    Event::fake();

    config()->set('swarm.guardrails.output', [BlocksOutputWhenContains::class]);
    $this->app->bind(BlocksOutputWhenContains::class, fn () => new BlocksOutputWhenContains('editor-out'));
    GuardrailContainer::refresh($this->app);

    expect(fn () => FakeSequentialSwarm::make()->prompt('task'))
        ->toThrow(GuardrailViolation::class);

    Event::assertDispatched(SwarmStarted::class);
    Event::assertNotDispatched(SwarmCompleted::class);
    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $e): bool => $e->exceptionClass === GuardrailViolation::class);
});

test('step guardrail fails on configured step index before completion', function () {
    Event::fake();

    config()->set('swarm.guardrails.step', [BlocksStepWhenIndex::class]);
    $this->app->bind(BlocksStepWhenIndex::class, fn () => new BlocksStepWhenIndex(1));
    GuardrailContainer::refresh($this->app);

    expect(fn () => FakeSequentialSwarm::make()->prompt('task'))
        ->toThrow(GuardrailViolation::class);

    Event::assertDispatched(SwarmStarted::class);
    Event::assertNotDispatched(SwarmCompleted::class);
});

test('queue does not dispatch when input guardrail fails', function () {
    Queue::fake();

    config()->set('swarm.guardrails.input', [BlocksInputWhenMatches::class]);
    $this->app->bind(BlocksInputWhenMatches::class, fn () => new BlocksInputWhenMatches('reject-queue'));
    GuardrailContainer::refresh($this->app);

    expect(fn () => FakeSequentialSwarm::make()->queue('reject-queue'))
        ->toThrow(GuardrailViolation::class);

    Queue::assertNothingPushed();
});

test('queue input guardrail persists preflight failure row before dispatch', function () {
    config()->set('swarm.guardrails.input', [BlocksInputWhenMatches::class]);
    $this->app->bind(BlocksInputWhenMatches::class, fn () => new BlocksInputWhenMatches('blocked-queue'));
    GuardrailContainer::refresh($this->app);

    $runId = 'guardrail-queue-preflight-'.uniqid('', true);

    expect(fn () => FakeSequentialSwarm::make()->queue(RunContext::from('blocked-queue', $runId)))
        ->toThrow(GuardrailViolation::class);

    $record = app(RunHistoryStore::class)->find($runId);
    expect($record)->not->toBeNull()
        ->and($record['status'])->toBe('failed')
        ->and(($record['error']['class'] ?? null))->toBe(GuardrailViolation::class);
});

test('queue input guardrail dispatches SwarmFailed before dispatch', function () {
    Event::fake();

    config()->set('swarm.guardrails.input', [BlocksInputWhenMatches::class]);
    $this->app->bind(BlocksInputWhenMatches::class, fn () => new BlocksInputWhenMatches('blocked-queue-event'));
    GuardrailContainer::refresh($this->app);

    expect(fn () => FakeSequentialSwarm::make()->queue('blocked-queue-event'))
        ->toThrow(GuardrailViolation::class);

    Event::assertNotDispatched(SwarmStarted::class);
    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $e): bool => $e->exceptionClass === GuardrailViolation::class);
});

test('stream input guardrail throws eagerly before stream response is returned', function () {
    Event::fake();

    config()->set('swarm.guardrails.input', [BlocksInputWhenMatches::class]);
    $this->app->bind(BlocksInputWhenMatches::class, fn () => new BlocksInputWhenMatches('reject-stream'));
    GuardrailContainer::refresh($this->app);

    expect(fn () => FakeSequentialSwarm::make()->stream('reject-stream'))
        ->toThrow(GuardrailViolation::class);

    Event::assertNotDispatched(SwarmStarted::class);
    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $e): bool => $e->exceptionClass === GuardrailViolation::class);
});

test('telemetry run failed records guardrail violation exception class', function () {
    config()->set('swarm.observability.enabled', true);
    config()->set('swarm.observability.listen_to_events', true);

    $sink = new RecordingSwarmTelemetrySink;
    $this->app->instance(SwarmTelemetrySink::class, $sink);
    $this->app->forgetInstance(SwarmTelemetryDispatcher::class);

    config()->set('swarm.guardrails.input', [BlocksInputWhenMatches::class]);
    $this->app->bind(BlocksInputWhenMatches::class, fn () => new BlocksInputWhenMatches('telemetry-block'));
    GuardrailContainer::refresh($this->app);

    expect(fn () => FakeSequentialSwarm::make()->prompt('telemetry-block'))
        ->toThrow(GuardrailViolation::class);

    $failed = array_values(array_filter(
        $sink->allRecords(),
        static fn (array $p): bool => ($p['category'] ?? '') === 'run.failed'
            && ($p['exception_class'] ?? null) === GuardrailViolation::class
    ));
    expect($failed)->not->toBeEmpty();
});
