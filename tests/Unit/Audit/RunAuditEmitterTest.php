<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\RunAuditEmitter;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;

beforeEach(function (): void {
    $this->sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $this->sink);
});

test('emitRunStarted composes the run.started payload from context, swarm class, topology, and execution mode', function (): void {
    $context = RunContext::fromTask('hello');
    $context->mergeMetadata(['parent_run_id' => 'parent-123']);

    app(RunAuditEmitter::class)->emitRunStarted(
        $context,
        'App\\Swarms\\ExampleSwarm',
        Topology::Sequential,
        ExecutionMode::Run,
    );

    $records = $this->sink->recordsForCategory('run.started');
    expect($records)->toHaveCount(1);
    expect($records[0])->toMatchArray([
        'run_id' => $context->runId,
        'parent_run_id' => 'parent-123',
        'swarm_class' => 'App\\Swarms\\ExampleSwarm',
        'topology' => 'sequential',
        'execution_mode' => 'run',
        'status' => 'started',
    ]);
});

test('emitRunCompleted uses response metadata, not context metadata, for the metadata payload', function (): void {
    $context = RunContext::fromTask('go');
    $context->mergeMetadata(['leaked' => 'should-not-appear-in-completed-allowlist']);
    config()->set('swarm.audit.metadata_allowlist', ['response_marker']);

    app(RunAuditEmitter::class)->emitRunCompleted(
        $context,
        'App\\Swarms\\ExampleSwarm',
        Topology::Sequential,
        ExecutionMode::Run,
        durationMs: 42,
        responseMetadata: ['response_marker' => 'present'],
    );

    $records = $this->sink->recordsForCategory('run.completed');
    expect($records)->toHaveCount(1);
    expect($records[0])->toMatchArray([
        'run_id' => $context->runId,
        'swarm_class' => 'App\\Swarms\\ExampleSwarm',
        'topology' => 'sequential',
        'execution_mode' => 'run',
        'status' => 'completed',
        'duration_ms' => 42,
    ]);
    expect($records[0]['metadata'] ?? [])->toBe(['response_marker' => 'present']);
});

test('emitRunFailed carries exception_class and duration_ms', function (): void {
    $context = RunContext::fromTask('boom');

    app(RunAuditEmitter::class)->emitRunFailed(
        $context,
        'App\\Swarms\\ExampleSwarm',
        Topology::Parallel,
        ExecutionMode::Queue,
        new RuntimeException('something broke'),
        durationMs: 1500,
    );

    $records = $this->sink->recordsForCategory('run.failed');
    expect($records)->toHaveCount(1);
    expect($records[0])->toMatchArray([
        'run_id' => $context->runId,
        'parent_run_id' => null,
        'swarm_class' => 'App\\Swarms\\ExampleSwarm',
        'topology' => 'parallel',
        'execution_mode' => 'queue',
        'status' => 'failed',
        'exception_class' => RuntimeException::class,
        'duration_ms' => 1500,
    ]);
});
