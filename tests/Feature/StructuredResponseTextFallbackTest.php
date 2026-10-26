<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Facades\Swarm;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StructuredOutputWorker;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StaticHierarchicalStructuredParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StructuredOutputParallelSwarm;

/*
 * laravel/ai 1.3 wraps every schema agent's result in a StructuredAgentResponse,
 * with an empty payload when the model's text did not decode; that response
 * stringifies to "[]". Each runner records the text instead. The fakes return
 * plain text with no braces, brackets or fences, so nothing can decode it into
 * a payload and the fallback is what is exercised on 1.3. On laravel/ai 1.0 the
 * same fakes produce a plain response, so these tests pin unchanged behaviour.
 */

const STRUCTURED_FALLBACK_TEXT = 'this is not json';

beforeEach(function () {
    StructuredOutputWorker::fake([STRUCTURED_FALLBACK_TEXT]);
    FakeWriter::fake(['writer-out']);
});

/**
 * @return list<string>
 */
function structuredFallbackStepOutputs(string $runId): array
{
    $history = app(SwarmHistory::class)->find($runId);

    return array_map(static fn (array $step): string => (string) $step['output'], $history['steps'] ?? []);
}

it('records the text of a sequential schema worker', function () {
    $response = Swarm::sequential([new StructuredOutputWorker, new FakeWriter])->prompt('sequential-task');

    expect($response->steps[0]->output)->toBe(STRUCTURED_FALLBACK_TEXT);

    FakeWriter::assertPrompted(STRUCTURED_FALLBACK_TEXT);
});

it('records the text of a sequential schema worker that runs before a streamed final agent', function () {
    $context = RunContext::from('sequential-stream-task');

    Swarm::sequential([new StructuredOutputWorker, new FakeWriter])->stream($context)->each(static fn () => null);

    expect(structuredFallbackStepOutputs($context->runId)[0] ?? null)->toBe(STRUCTURED_FALLBACK_TEXT);

    FakeWriter::assertPrompted(STRUCTURED_FALLBACK_TEXT);
});

it('records the text of a parallel schema worker', function () {
    $response = StructuredOutputParallelSwarm::make()->prompt('parallel-task');

    expect($response->steps[0]->output)->toBe(STRUCTURED_FALLBACK_TEXT);
});

it('records the text of a schema branch on a hierarchical parallel node', function () {
    $response = StaticHierarchicalStructuredParallelSwarm::make()->prompt('hierarchical-parallel-task');

    expect($response->output)->toBe(STRUCTURED_FALLBACK_TEXT);
});

it('records the text of a schema branch on a streamed hierarchical parallel node', function () {
    $context = RunContext::from('hierarchical-parallel-stream-task');

    StaticHierarchicalStructuredParallelSwarm::make()->stream($context)->each(static fn () => null);

    $steps = collect(app(SwarmHistory::class)->find($context->runId)['steps'] ?? []);

    expect($steps->firstWhere('agent_class', StructuredOutputWorker::class)['output'] ?? null)->toBe(STRUCTURED_FALLBACK_TEXT)
        ->and($steps->firstWhere('agent_class', FakeWriter::class)['output'] ?? null)->toBe('writer-out');
});

it('reports invalid coordinator json and records the coordinator text', function () {
    FakeHierarchicalCoordinator::fake([STRUCTURED_FALLBACK_TEXT]);
    $context = RunContext::from('coordinator-task');

    expect(fn () => Swarm::hierarchical(new FakeHierarchicalCoordinator, [new FakeWriter])->prompt($context))
        ->toThrow(SwarmException::class, 'Hierarchical coordinator output must be valid JSON matching the declared route-plan schema.');

    $history = app(SwarmHistory::class)->find($context->runId);

    expect($history['status'])->toBe('failed')
        ->and($history['steps'][0]['agent_class'])->toBe(FakeHierarchicalCoordinator::class)
        ->and($history['steps'][0]['output'])->toBe(STRUCTURED_FALLBACK_TEXT);
});

it('reports invalid coordinator json and records the coordinator text when the hierarchical swarm streams', function () {
    FakeHierarchicalCoordinator::fake([STRUCTURED_FALLBACK_TEXT]);
    $context = RunContext::from('coordinator-stream-task');

    expect(fn () => Swarm::hierarchical(new FakeHierarchicalCoordinator, [new FakeWriter])->stream($context)->each(static fn () => null))
        ->toThrow(SwarmException::class, 'Hierarchical coordinator output must be valid JSON matching the declared route-plan schema.');

    $history = app(SwarmHistory::class)->find($context->runId);

    expect($history['status'])->toBe('failed')
        ->and($history['steps'][0]['agent_class'])->toBe(FakeHierarchicalCoordinator::class)
        ->and($history['steps'][0]['output'])->toBe(STRUCTURED_FALLBACK_TEXT);
});

it('records the text of a durable schema branch', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');

    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $runId = StructuredOutputParallelSwarm::make()->dispatchDurable('durable-parallel-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel') as $branch) {
        (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
    }

    $branches = app(DurableRunStore::class)->branchesFor($runId, 'parallel');

    expect($branches[0]['output'])->toBe(STRUCTURED_FALLBACK_TEXT);
});
