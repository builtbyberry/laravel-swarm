<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Facades\Swarm;
use BuiltByBerry\LaravelSwarm\Testing\Audit\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;

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
