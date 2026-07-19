<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Facades\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\PendingSwarmRun;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksInputWhenMatches;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;

beforeEach(function () {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

it('returns a fluent PendingSwarmRun from each inline entry point', function () {
    expect(Swarm::sequential([new FakeResearcher]))->toBeInstanceOf(PendingSwarmRun::class)
        ->and(Swarm::parallel([new FakeResearcher]))->toBeInstanceOf(PendingSwarmRun::class)
        ->and(Swarm::hierarchical(new FakeHierarchicalCoordinator, [new FakeWriter]))->toBeInstanceOf(PendingSwarmRun::class);
});

it('runs an inline sequential swarm in order, threading outputs, through the governed pipeline', function () {
    $audit = SwarmFake::interceptSwarmAuditSink();

    $response = Swarm::sequential([new FakeResearcher, new FakeWriter, new FakeEditor])->prompt('original-task');

    expect($response)->toBeInstanceOf(SwarmResponse::class)
        ->and((string) $response)->toBe('editor-out')
        ->and($response->steps)->toHaveCount(3);

    // Sequential topology: each output feeds the next.
    FakeResearcher::assertPrompted('original-task');
    FakeWriter::assertPrompted('research-out');
    FakeEditor::assertPrompted('writer-out');

    // Same governed evidence chain as a class-based swarm.
    $audit->assertAuditChain(['run.started', 'run.completed']);
    $audit->assertStepCount(3);
});

it('runs an inline parallel swarm with each agent against the original task', function () {
    $response = Swarm::parallel([new FakeResearcher, new FakeWriter, new FakeEditor])->prompt('shared-task');

    expect($response->steps)->toHaveCount(3);

    // Parallel topology: every agent sees the original task, not a threaded one.
    FakeResearcher::assertPrompted('shared-task');
    FakeWriter::assertPrompted('shared-task');
    FakeEditor::assertPrompted('shared-task');
});

it('runs an inline hierarchical swarm where the coordinator routes to a worker', function () {
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
            ],
        ]),
    ]);

    $response = Swarm::hierarchical(new FakeHierarchicalCoordinator, [new FakeWriter])->prompt('hierarchical-task');

    expect($response->output)->toBe('writer-out')
        ->and($response->metadata['coordinator_agent_class'])->toBe(FakeHierarchicalCoordinator::class)
        ->and($response->metadata['route_plan_start'])->toBe('writer_node');

    FakeWriter::assertPrompted('writer-task');
});

it('applies per-call guardrails to an inline swarm', function () {
    expect(fn () => Swarm::sequential([new FakeResearcher, new FakeWriter])
        ->guardrails([new BlocksInputWhenMatches('blocked-token')])
        ->prompt('blocked-token'))
        ->toThrow(GuardrailViolation::class);
});
