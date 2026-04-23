<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Jobs\NoOpQueuedJob;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support\ResolvedSwarmOutput;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support\UnboundQueuedDependency;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ContainerResolvedQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DependencyInjectedQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\UnresolvableQueuedSwarm;

function preventQueuedSwarmRedispatch(object $response): void
{
    $dispatchableProperty = new ReflectionProperty($response, 'dispatchable');
    $dispatchableProperty->setAccessible(true);

    $dispatchable = $dispatchableProperty->getValue($response);

    $jobProperty = new ReflectionProperty($dispatchable, 'job');
    $jobProperty->setAccessible(true);
    $jobProperty->setValue($dispatchable, new NoOpQueuedJob);
}

beforeEach(function () {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('queued swarm jobs can execute with a preserved run context', function () {
    $context = RunContext::from([
        'input' => 'queued-task',
        'data' => ['draft_id' => 42],
        'metadata' => ['tenant_id' => 'acme'],
    ], 'queued-run-id');
    $job = new InvokeSwarm(FakeSequentialSwarm::class, $context);

    $job->handle(app(SwarmRunner::class));

    FakeResearcher::assertPrompted('queued-task');
    FakeWriter::assertPrompted('research-out');
    FakeEditor::assertPrompted('writer-out');
    expect($job->task->data)
        ->toHaveKey('draft_id', 42)
        ->toHaveKey('last_output', 'editor-out')
        ->toHaveKey('steps', 3);
    expect($job->task->metadata)
        ->toHaveKey('tenant_id', 'acme')
        ->toHaveKey('swarm_class', FakeSequentialSwarm::class)
        ->toHaveKey('topology', 'sequential');
});

test('queued swarm completion callbacks run through the pending dispatch path', function () {
    $state = (object) ['response' => null];

    $queued = FakeSequentialSwarm::make()
        ->queue('queued-task')
        ->then(function (SwarmResponse $response) use ($state): void {
            $state->response = $response;
        });

    $job = $queued->getJob();
    preventQueuedSwarmRedispatch($queued);
    $job->handle(app(SwarmRunner::class));

    expect($state->response)->toBeInstanceOf(SwarmResponse::class);
    expect($state->response?->output)->toBe('editor-out');
});

test('queued swarm response preserves fluency after queue configuration methods', function () {
    $state = (object) ['response' => null];

    $queued = FakeSequentialSwarm::make()
        ->queue('queued-task')
        ->onQueue('swarm-testing')
        ->then(function (SwarmResponse $response) use ($state): void {
            $state->response = $response;
        });

    expect($queued)->toBeInstanceOf(QueuedSwarmResponse::class);
    expect($queued->runId)->not->toBeNull();
    expect($queued->getJob()->queue)->toBe('swarm-testing');

    $job = $queued->getJob();
    preventQueuedSwarmRedispatch($queued);
    $job->handle(app(SwarmRunner::class));

    expect($state->response)->toBeInstanceOf(SwarmResponse::class);
    expect($state->response?->output)->toBe('editor-out');
});

test('queued swarm response returns raw values for non dispatch proxy methods', function () {
    $queued = FakeSequentialSwarm::make()->queue('queued-task');

    expect($queued->getJob())->toBeInstanceOf(InvokeSwarm::class);
});

test('queued swarm failure callbacks run through the pending dispatch path', function () {
    $state = (object) ['exception_class' => null, 'exception_message' => null];
    $queued = FailingQueuedSwarm::make()
        ->queue('queued-task')
        ->catch(function (Throwable $exception) use ($state): void {
            $state->exception_class = $exception::class;
            $state->exception_message = $exception->getMessage();
        });
    $job = $queued->getJob();
    preventQueuedSwarmRedispatch($queued);

    try {
        $job->handle(app(SwarmRunner::class));

        $this->fail('Expected the queued swarm to throw.');
    } catch (RuntimeException $exception) {
        $job->failed($exception);
        expect($exception->getMessage())->toBe('Queued swarm failed.');
    }

    expect($state->exception_class)->toBe(RuntimeException::class);
    expect($state->exception_message)->toBe('Queued swarm failed.');
});

test('queued swarms resolve a fresh instance from the container when handled', function () {
    $state = (object) ['response' => null];

    $resolvedOutput = new ResolvedSwarmOutput;
    $resolvedOutput->value = 'resolved-output';

    app()->instance(ResolvedSwarmOutput::class, $resolvedOutput);

    $queued = DependencyInjectedQueuedSwarm::make()
        ->queue('queued-task')
        ->then(function (SwarmResponse $response) use ($state): void {
            $state->response = $response;
        });

    $job = $queued->getJob();
    preventQueuedSwarmRedispatch($queued);
    $job->handle(app(SwarmRunner::class));

    expect($state->response)->toBeInstanceOf(SwarmResponse::class);
    expect($state->response?->output)->toBe('resolved-output');
});

test('queue fails fast for swarms with runtime constructor state', function () {
    expect(fn () => (new ContainerResolvedQueuedSwarm('runtime-output'))->queue('queued-task'))
        ->toThrow(
            NonQueueableSwarmException::class,
            'Queued swarms must be container-resolvable workflow definitions. [BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ContainerResolvedQueuedSwarm] cannot be queued because constructor parameter [$output] uses [string] instead of a container dependency. Do not put per-execution state in the swarm constructor; pass it in the task or RunContext instead.',
        );
});

test('queue fails fast when the swarm cannot be resolved from the container', function () {
    $dependency = new class implements UnboundQueuedDependency {};

    expect(fn () => (new UnresolvableQueuedSwarm($dependency))->queue('queued-task'))
        ->toThrow(
            NonQueueableSwarmException::class,
            'Queued swarms must be container-resolvable workflow definitions. [BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\UnresolvableQueuedSwarm] could not be resolved from the container for queued execution. Underlying container error: Target [BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support\UnboundQueuedDependency] is not instantiable while building [BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\UnresolvableQueuedSwarm].',
        );
});

test('run still works for swarms with runtime constructor state', function () {
    $response = (new ContainerResolvedQueuedSwarm('runtime-output'))->run('queued-task');

    expect($response->output)->toBe('runtime-output');
});
