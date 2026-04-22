<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;

beforeEach(function () {
    FakeResearcher::fake(['parallel-a']);
    FakeWriter::fake(['parallel-b']);
    FakeEditor::fake(['parallel-c']);
});

test('parallel swarm runs each agent with the original task', function () {
    $task = 'shared-task';
    $response = FakeParallelSwarm::make()->run($task);

    FakeResearcher::assertPrompted($task);
    FakeWriter::assertPrompted($task);
    FakeEditor::assertPrompted($task);

    expect($response->steps)->toHaveCount(3);

    foreach ($response->steps as $step) {
        expect($step->input)->toBe($task);
    }

    expect((string) $response)->toContain('parallel-a');
    expect((string) $response)->toContain('parallel-b');
    expect((string) $response)->toContain('parallel-c');
});

test('parallel swarm records artifacts and metadata', function () {
    $response = FakeParallelSwarm::make()->run('shared-task');

    expect($response->metadata['topology'])->toBe('parallel');
    expect($response->artifacts)->toHaveCount(3);
    expect($response->steps[0]->artifacts)->toHaveCount(1);
    expect($response->steps[0]->artifacts[0]->name)->toBe('agent_output');
});
