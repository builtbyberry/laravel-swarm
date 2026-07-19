<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Facades\Swarm;
use BuiltByBerry\LaravelSwarm\Testing\Audit\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function () {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

it('installs a recording sink and returns it', function () {
    expect(SwarmFake::interceptSwarmAuditSink())->toBeInstanceOf(RecordingSwarmAuditSink::class);
});

it('records the full audit chain for a single-agent run', function () {
    $audit = SwarmFake::interceptSwarmAuditSink();

    Swarm::agent(new FakeResearcher)->prompt('a task');

    $audit->assertAuditChain(['run.started', 'step.started', 'step.completed', 'run.completed']);
    $audit->assertEmittedAudit('run.completed');
    $audit->assertStepCount(1);
    $audit->assertNotEmittedAudit('run.failed');
});

it('counts every step for a multi-agent run', function () {
    $audit = SwarmFake::interceptSwarmAuditSink();

    FakeSequentialSwarm::make()->prompt('original-task');

    $audit->assertAuditChain(['run.started', 'run.completed']);
    $audit->assertStepCount(3);
});

it('asserts on a specific evidence payload with a matcher', function () {
    $audit = SwarmFake::interceptSwarmAuditSink();

    Swarm::agent(new FakeResearcher)->prompt('a task');

    $audit->assertEmittedAudit('run.completed', fn (array $payload): bool => ($payload['category'] ?? null) === 'run.completed');
});

it('records the audit chain for a queued run', function () {
    config()->set('queue.default', 'sync');
    config()->set('swarm.capture.active_context', true);

    $audit = SwarmFake::interceptSwarmAuditSink();

    FakeSequentialSwarm::make()->queue('original-task');

    $audit->assertAuditChain(['run.started', 'run.completed']);
    $audit->assertStepCount(3);
});

it('forwards every payload to an optional delegate behind the recorder', function () {
    $delegate = new RecordingSwarmAuditSink;
    $audit = SwarmFake::interceptSwarmAuditSink($delegate);

    Swarm::agent(new FakeResearcher)->prompt('a task');

    $audit->assertEmittedAudit('run.completed');
    $delegate->assertEmittedAudit('run.completed');
});

// --- Negative direction: prove each assertion actually catches a regression ---

it('assertAuditChain fails when categories are out of order or absent', function () {
    $sink = new RecordingSwarmAuditSink;
    $sink->emit('run.started', ['category' => 'run.started']);
    $sink->emit('run.completed', ['category' => 'run.completed']);

    expect(fn () => $sink->assertAuditChain(['run.completed', 'run.started']))
        ->toThrow(AssertionFailedError::class);
    expect(fn () => $sink->assertAuditChain(['run.started', 'step.started', 'run.completed']))
        ->toThrow(AssertionFailedError::class);
});

it('assertStepCount fails on the wrong count', function () {
    $sink = new RecordingSwarmAuditSink;
    $sink->emit('step.started', ['category' => 'step.started']);

    expect(fn () => $sink->assertStepCount(2))->toThrow(AssertionFailedError::class);
});

it('assertNotEmittedAudit fails when the category was emitted', function () {
    $sink = new RecordingSwarmAuditSink;
    $sink->emit('run.failed', ['category' => 'run.failed']);

    expect(fn () => $sink->assertNotEmittedAudit('run.failed'))->toThrow(AssertionFailedError::class);
});

it('assertEmittedAudit fails when absent or the matcher never matches', function () {
    $sink = new RecordingSwarmAuditSink;
    $sink->emit('run.completed', ['category' => 'run.completed', 'x' => 1]);

    expect(fn () => $sink->assertEmittedAudit('run.failed'))->toThrow(AssertionFailedError::class);
    expect(fn () => $sink->assertEmittedAudit('run.completed', fn (array $p): bool => ($p['x'] ?? null) === 999))
        ->toThrow(AssertionFailedError::class);
});
