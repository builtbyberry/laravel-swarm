<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Event;

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

test('sequential swarm accepts structured array tasks', function () {
    $response = FakeSequentialSwarm::make()->run([
        'ticket_id' => 'TKT-1234',
        'customer_tier' => 'enterprise',
        'issue' => 'Need help with a billing mismatch.',
    ]);

    expect($response->context?->data)
        ->toHaveKey('ticket_id', 'TKT-1234')
        ->toHaveKey('customer_tier', 'enterprise')
        ->toHaveKey('issue', 'Need help with a billing mismatch.');
});

test('sequential swarm accepts explicit run contexts', function () {
    $context = RunContext::from([
        'input' => 'Draft a response for the customer.',
        'data' => ['ticket_id' => 'TKT-1234'],
        'metadata' => ['tenant_id' => 'acme'],
    ], 'structured-run-id');

    $response = FakeSequentialSwarm::make()->run($context);

    expect($response->context?->runId)->toBe('structured-run-id');
    expect($response->context?->data)->toHaveKey('ticket_id', 'TKT-1234');
    expect($response->context?->metadata)->toHaveKey('tenant_id', 'acme');
});

test('sequential swarm exposes and persists generic context and artifacts', function () {
    $response = FakeSequentialSwarm::make()->run('original-task');

    expect($response->context)->not->toBeNull();
    expect($response->metadata['run_id'])->toBeString();
    expect($response->artifacts)->toHaveCount(3);
    expect($response->artifacts[0]->name)->toBe('agent_output');
    expect($response->context?->data['last_output'])->toBe('editor-out');

    $runId = $response->metadata['run_id'];
    $storedContext = app(ContextStore::class)->find($runId);
    $storedArtifacts = app(ArtifactRepository::class)->all($runId);
    $storedHistory = app(RunHistoryStore::class)->find($runId);

    expect($storedContext['metadata']['swarm_class'])->toBe(FakeSequentialSwarm::class);
    expect($storedArtifacts)->toHaveCount(3);
    expect($storedHistory['status'])->toBe('completed');
    expect($storedHistory['steps'])->toHaveCount(3);
    expect($storedHistory['output'])->toBe('editor-out');
});

test('sequential swarm dispatches lifecycle events', function () {
    Event::fake();

    $response = FakeSequentialSwarm::make()->run('event-task');

    Event::assertDispatched(SwarmStarted::class, fn (SwarmStarted $event) => $event->runId === $response->metadata['run_id']
        && $event->input === 'event-task'
        && $event->executionMode === 'run');
    Event::assertDispatchedTimes(SwarmStepStarted::class, 3);
    Event::assertDispatched(SwarmStepCompleted::class, fn (SwarmStepCompleted $event) => $event->agentClass === FakeResearcher::class && $event->artifacts !== []);
    Event::assertDispatched(SwarmCompleted::class, fn (SwarmCompleted $event) => $event->runId === $response->metadata['run_id'] && $event->output === 'editor-out');
});

test('sequential swarm preserves accumulated metadata on completion', function () {
    Event::fake();

    $response = FakeSequentialSwarm::make()->run('metadata-task');
    $storedHistory = app(RunHistoryStore::class)->find($response->metadata['run_id']);

    expect($response->metadata)
        ->toHaveKey('swarm_class', FakeSequentialSwarm::class)
        ->toHaveKey('last_agent', FakeEditor::class);
    expect($storedHistory['metadata'])
        ->toHaveKey('swarm_class', FakeSequentialSwarm::class)
        ->toHaveKey('last_agent', FakeEditor::class);

    Event::assertDispatched(SwarmCompleted::class, fn (SwarmCompleted $event) => $event->metadata['swarm_class'] === FakeSequentialSwarm::class && $event->metadata['last_agent'] === FakeEditor::class);
});
