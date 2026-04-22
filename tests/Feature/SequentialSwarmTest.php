<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;

beforeEach(function () {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('sequential swarm runs agents in order and threads outputs', function () {
    $response = FakeSequentialSwarm::make()->run('original-task');

    expect((string) $response)->toBe('editor-out');
    expect($response->steps)->toHaveCount(3);

    FakeResearcher::assertPrompted('original-task');
    FakeWriter::assertPrompted('research-out');
    FakeEditor::assertPrompted('writer-out');

    expect($response->steps[0]->agentClass)->toBe(FakeResearcher::class);
    expect($response->steps[0]->input)->toBe('original-task');
    expect($response->steps[0]->output)->toBe('research-out');

    expect($response->steps[2]->output)->toBe('editor-out');
});

test('sequential swarm records usage from agent responses', function () {
    $response = FakeSequentialSwarm::make()->run('usage-task');

    expect($response->usage)->toBeArray();
});
