<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('database.default', 'testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

test('database context store persists the same context shape as cache', function () {
    $store = app(DatabaseContextStore::class);
    $context = RunContext::from([
        'input' => 'database-task',
        'data' => ['last_output' => 'done'],
        'metadata' => ['swarm_class' => 'ExampleSwarm'],
    ], 'context-run-id');
    $context->addArtifact(new SwarmArtifact(
        name: 'agent_output',
        content: ['summary' => 'artifact'],
        metadata: ['index' => 0],
        stepAgentClass: FakeEditor::class,
    ));

    $store->put($context, 60);

    expect($store->find('context-run-id'))->toBe($context->toArray());
});

test('database artifact repository persists explicit json payloads', function () {
    $repository = app(DatabaseArtifactRepository::class);

    $repository->storeMany('artifact-run-id', [
        new SwarmArtifact(
            name: 'agent_output',
            content: ['title' => 'Outline'],
            metadata: ['index' => 0],
            stepAgentClass: FakeEditor::class,
        ),
    ], 60);

    expect($repository->all('artifact-run-id'))->toBe([
        [
            'name' => 'agent_output',
            'content' => ['title' => 'Outline'],
            'metadata' => ['index' => 0],
            'step_agent_class' => FakeEditor::class,
        ],
    ]);
});

test('database run history store persists start step completion and failure payloads', function () {
    $history = app(DatabaseRunHistoryStore::class);
    $context = RunContext::from('history-task', 'history-run-id');

    $history->start('history-run-id', 'ExampleSwarm', 'sequential', $context, ['run_id' => 'history-run-id'], 60);
    $history->recordStep('history-run-id', new SwarmStep(
        agentClass: FakeEditor::class,
        input: 'history-task',
        output: 'first-output',
        artifacts: [
            new SwarmArtifact(
                name: 'agent_output',
                content: ['draft' => 'first-output'],
                metadata: ['index' => 0],
                stepAgentClass: FakeEditor::class,
            ),
        ],
        metadata: ['index' => 0],
    ), 60);
    $history->complete('history-run-id', new SwarmResponse(
        output: 'final-output',
        steps: [],
        usage: ['input_tokens' => 10],
        context: $context,
        artifacts: [
            new SwarmArtifact(
                name: 'agent_output',
                content: 'final-output',
                metadata: ['index' => 0],
                stepAgentClass: FakeEditor::class,
            ),
        ],
        metadata: ['run_id' => 'history-run-id'],
    ), 60);

    $stored = $history->find('history-run-id');

    expect($stored['status'])->toBe('completed');
    expect($stored['steps'])->toHaveCount(1);
    expect($stored['usage'])->toBe(['input_tokens' => 10]);
    expect($stored['artifacts'][0]['content'])->toBe('final-output');

    $history->fail('history-run-id', new Exception('stream failed'), 60);

    expect($history->find('history-run-id')['error'])->toBe([
        'message' => 'stream failed',
        'class' => Exception::class,
    ]);

    expect($history->query(limit: 10)[0]['run_id'])->toBe('history-run-id');
    expect($history->query(status: 'failed', limit: 10)[0]['status'])->toBe('failed');
});

test('database persistence repositories honor overridden table names when matching tables exist', function () {
    Schema::create('custom_swarm_contexts', function (Blueprint $table): void {
        $table->string('run_id')->primary();
        $table->text('input');
        $table->json('data');
        $table->json('metadata');
        $table->json('artifacts');
        $table->timestamps();
    });

    Schema::create('custom_swarm_artifacts', function (Blueprint $table): void {
        $table->id();
        $table->string('run_id')->index();
        $table->string('name');
        $table->longText('content');
        $table->json('metadata');
        $table->string('step_agent_class')->nullable();
        $table->timestamps();
    });

    Schema::create('custom_swarm_histories', function (Blueprint $table): void {
        $table->string('run_id')->primary();
        $table->string('swarm_class');
        $table->string('topology');
        $table->string('status');
        $table->json('context');
        $table->json('metadata');
        $table->json('steps');
        $table->longText('output')->nullable();
        $table->json('usage');
        $table->json('error')->nullable();
        $table->json('artifacts');
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();
    });

    config()->set('swarm.tables.contexts', 'custom_swarm_contexts');
    config()->set('swarm.tables.artifacts', 'custom_swarm_artifacts');
    config()->set('swarm.tables.history', 'custom_swarm_histories');

    $contextStore = app(DatabaseContextStore::class);
    $artifactRepository = app(DatabaseArtifactRepository::class);
    $historyStore = app(DatabaseRunHistoryStore::class);
    $context = RunContext::from('custom-table-task', 'custom-table-run');

    $contextStore->put($context, 60);
    $artifactRepository->storeMany('custom-table-run', [
        new SwarmArtifact(
            name: 'agent_output',
            content: 'custom-artifact',
            metadata: ['index' => 0],
            stepAgentClass: FakeEditor::class,
        ),
    ], 60);
    $historyStore->start('custom-table-run', 'ExampleSwarm', 'sequential', $context, ['run_id' => 'custom-table-run'], 60);

    expect($contextStore->find('custom-table-run')['input'])->toBe('custom-table-task');
    expect($artifactRepository->all('custom-table-run')[0]['content'])->toBe('custom-artifact');
    expect($historyStore->find('custom-table-run')['status'])->toBe('running');
});
