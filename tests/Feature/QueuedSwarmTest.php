<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Jobs\NoOpQueuedJob;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ContainerResolvedQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;

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
    $context = RunContext::from('queued-task', 'queued-run-id');
    $job = new InvokeSwarm(FakeSequentialSwarm::class, $context);

    $job->handle(app(SwarmRunner::class));

    FakeResearcher::assertPrompted('queued-task');
    FakeWriter::assertPrompted('research-out');
    FakeEditor::assertPrompted('writer-out');
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

    app()->instance(ContainerResolvedQueuedSwarm::class, new ContainerResolvedQueuedSwarm('resolved-output'));

    $queued = (new ContainerResolvedQueuedSwarm('original-output'))
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
