<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeMixedParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeMixedSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithQueuedExecutionAttribute;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('queued execution policy dispatches a queued swarm with preserved context', function () {
    Bus::fake();

    $response = SwarmWithQueuedExecutionAttribute::make()->dispatch([
        'input' => 'queued-task',
        'metadata' => ['source' => 'test-suite'],
    ]);

    expect($response)->toBeInstanceOf(QueuedSwarmResponse::class);
    expect($response->runId)->toBeString();

    Bus::assertDispatched(InvokeSwarm::class, function (InvokeSwarm $job) use ($response): bool {
        return $job->task instanceof RunContext
            && $job->task->runId === $response->runId
            && $job->task->metadata['source'] === 'test-suite'
            && $job->task->input === 'queued-task';
    });
});

test('mixed execution queues parallel swarms', function () {
    Bus::fake();

    $response = FakeMixedParallelSwarm::make()->dispatch('parallel-task');

    expect($response)->toBeInstanceOf(QueuedSwarmResponse::class);
    Bus::assertDispatched(InvokeSwarm::class);
});

test('mixed execution runs sequential swarms synchronously', function () {
    Bus::fake();

    $response = FakeMixedSequentialSwarm::make()->dispatch('sync-task');

    expect($response)->toBeInstanceOf(SwarmResponse::class);
    Bus::assertNotDispatched(InvokeSwarm::class);
});
