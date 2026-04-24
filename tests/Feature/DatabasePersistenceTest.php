<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function () {
    config()->set('database.default', 'testing');
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
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
    expect(DB::table('swarm_contexts')->where('run_id', 'context-run-id')->value('expires_at'))->not->toBeNull();
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
    expect(DB::table('swarm_artifacts')->where('run_id', 'artifact-run-id')->value('expires_at'))->not->toBeNull();
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
    expect($stored['finished_at'])->not->toBeNull();
    expect(DB::table('swarm_run_histories')->where('run_id', 'history-run-id')->value('expires_at'))->not->toBeNull();

    $history->fail('history-run-id', new Exception('stream failed'), 60);

    expect($history->find('history-run-id')['error'])->toBe([
        'message' => 'stream failed',
        'class' => Exception::class,
    ]);
    expect($history->find('history-run-id')['finished_at'])->not->toBeNull();

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
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    Schema::create('custom_swarm_artifacts', function (Blueprint $table): void {
        $table->id();
        $table->string('run_id')->index();
        $table->string('name');
        $table->longText('content');
        $table->json('metadata');
        $table->string('step_agent_class')->nullable();
        $table->timestamp('expires_at')->nullable();
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
        $table->timestamp('expires_at')->nullable();
        $table->string('execution_token')->nullable();
        $table->timestamp('leased_until')->nullable();
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

test('swarm prune removes expired database persistence rows and preserves active rows', function () {
    $now = Carbon::now('UTC');

    DB::table('swarm_contexts')->insert([
        [
            'run_id' => 'expired-context',
            'input' => 'expired',
            'data' => json_encode([]),
            'metadata' => json_encode([]),
            'artifacts' => json_encode([]),
            'expires_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'run_id' => 'active-context',
            'input' => 'active',
            'data' => json_encode([]),
            'metadata' => json_encode([]),
            'artifacts' => json_encode([]),
            'expires_at' => $now->copy()->addMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    DB::table('swarm_artifacts')->insert([
        [
            'run_id' => 'expired-artifact',
            'name' => 'agent_output',
            'content' => json_encode('expired'),
            'metadata' => json_encode([]),
            'step_agent_class' => FakeEditor::class,
            'expires_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'run_id' => 'active-artifact',
            'name' => 'agent_output',
            'content' => json_encode('active'),
            'metadata' => json_encode([]),
            'step_agent_class' => FakeEditor::class,
            'expires_at' => $now->copy()->addMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    DB::table('swarm_run_histories')->insert([
        [
            'run_id' => 'expired-history',
            'swarm_class' => FakeSequentialSwarm::class,
            'topology' => 'sequential',
            'status' => 'completed',
            'context' => json_encode(RunContext::from('expired-history-task', 'expired-history')->toArray()),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'output' => 'expired',
            'usage' => json_encode([]),
            'error' => null,
            'artifacts' => json_encode([]),
            'finished_at' => $now->copy()->subMinute(),
            'expires_at' => $now->copy()->subMinute(),
            'execution_token' => null,
            'leased_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'run_id' => 'active-history',
            'swarm_class' => FakeSequentialSwarm::class,
            'topology' => 'sequential',
            'status' => 'completed',
            'context' => json_encode(RunContext::from('active-history-task', 'active-history')->toArray()),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'output' => 'active',
            'usage' => json_encode([]),
            'error' => null,
            'artifacts' => json_encode([]),
            'finished_at' => $now->copy()->subMinute(),
            'expires_at' => $now->copy()->addMinute(),
            'execution_token' => null,
            'leased_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'run_id' => 'running-history',
            'swarm_class' => FakeSequentialSwarm::class,
            'topology' => 'sequential',
            'status' => 'running',
            'context' => json_encode(RunContext::from('running-history-task', 'running-history')->toArray()),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'output' => null,
            'usage' => json_encode([]),
            'error' => null,
            'artifacts' => json_encode([]),
            'finished_at' => null,
            'expires_at' => $now->copy()->subMinute(),
            'execution_token' => 'active-token',
            'leased_until' => $now->copy()->addMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_contexts')->where('run_id', 'expired-context')->exists())->toBeFalse();
    expect(DB::table('swarm_contexts')->where('run_id', 'active-context')->exists())->toBeTrue();
    expect(DB::table('swarm_artifacts')->where('run_id', 'expired-artifact')->exists())->toBeFalse();
    expect(DB::table('swarm_artifacts')->where('run_id', 'active-artifact')->exists())->toBeTrue();
    expect(DB::table('swarm_run_histories')->where('run_id', 'expired-history')->exists())->toBeFalse();
    expect(DB::table('swarm_run_histories')->where('run_id', 'active-history')->exists())->toBeTrue();
    expect(DB::table('swarm_run_histories')->where('run_id', 'running-history')->exists())->toBeTrue();
});

test('swarm prune preserves active-run contexts and artifacts and respects custom history tables', function () {
    $now = Carbon::now('UTC');

    Schema::create('custom_swarm_history_records', function (Blueprint $table): void {
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
        $table->timestamp('expires_at')->nullable();
        $table->string('execution_token')->nullable();
        $table->timestamp('leased_until')->nullable();
        $table->timestamps();
    });

    Schema::create('custom_swarm_context_records', function (Blueprint $table): void {
        $table->string('run_id')->primary();
        $table->text('input');
        $table->json('data');
        $table->json('metadata');
        $table->json('artifacts');
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    Schema::create('custom_swarm_artifact_records', function (Blueprint $table): void {
        $table->id();
        $table->string('run_id')->index();
        $table->string('name');
        $table->longText('content');
        $table->json('metadata');
        $table->string('step_agent_class')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    config()->set('swarm.tables.history', 'custom_swarm_history_records');
    config()->set('swarm.tables.contexts', 'custom_swarm_context_records');
    config()->set('swarm.tables.artifacts', 'custom_swarm_artifact_records');

    DB::table('custom_swarm_history_records')->insert([
        [
            'run_id' => 'custom-running-history',
            'swarm_class' => FakeSequentialSwarm::class,
            'topology' => 'sequential',
            'status' => 'running',
            'context' => json_encode(RunContext::from('custom-running-task', 'custom-running-history')->toArray()),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'output' => null,
            'usage' => json_encode([]),
            'error' => null,
            'artifacts' => json_encode([]),
            'finished_at' => null,
            'expires_at' => $now->copy()->subMinute(),
            'execution_token' => 'active-token',
            'leased_until' => $now->copy()->addMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'run_id' => 'custom-completed-history',
            'swarm_class' => FakeSequentialSwarm::class,
            'topology' => 'sequential',
            'status' => 'completed',
            'context' => json_encode(RunContext::from('custom-completed-task', 'custom-completed-history')->toArray()),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'output' => 'done',
            'usage' => json_encode([]),
            'error' => null,
            'artifacts' => json_encode([]),
            'finished_at' => $now->copy()->subMinute(),
            'expires_at' => $now->copy()->subMinute(),
            'execution_token' => null,
            'leased_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    DB::table('custom_swarm_context_records')->insert([
        [
            'run_id' => 'custom-running-history',
            'input' => 'running',
            'data' => json_encode([]),
            'metadata' => json_encode([]),
            'artifacts' => json_encode([]),
            'expires_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'run_id' => 'custom-completed-history',
            'input' => 'completed',
            'data' => json_encode([]),
            'metadata' => json_encode([]),
            'artifacts' => json_encode([]),
            'expires_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    DB::table('custom_swarm_artifact_records')->insert([
        [
            'run_id' => 'custom-running-history',
            'name' => 'agent_output',
            'content' => json_encode('running'),
            'metadata' => json_encode([]),
            'step_agent_class' => FakeEditor::class,
            'expires_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'run_id' => 'custom-completed-history',
            'name' => 'agent_output',
            'content' => json_encode('completed'),
            'metadata' => json_encode([]),
            'step_agent_class' => FakeEditor::class,
            'expires_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    Artisan::call('swarm:prune');

    expect(DB::table('custom_swarm_history_records')->where('run_id', 'custom-running-history')->exists())->toBeTrue();
    expect(DB::table('custom_swarm_history_records')->where('run_id', 'custom-completed-history')->exists())->toBeFalse();
    expect(DB::table('custom_swarm_context_records')->where('run_id', 'custom-running-history')->exists())->toBeTrue();
    expect(DB::table('custom_swarm_context_records')->where('run_id', 'custom-completed-history')->exists())->toBeFalse();
    expect(DB::table('custom_swarm_artifact_records')->where('run_id', 'custom-running-history')->exists())->toBeTrue();
    expect(DB::table('custom_swarm_artifact_records')->where('run_id', 'custom-completed-history')->exists())->toBeFalse();
});

test('queued database swarms fail clearly when the history table is missing lease columns', function () {
    Schema::create('legacy_swarm_histories', function (Blueprint $table): void {
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
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    config()->set('swarm.tables.history', 'legacy_swarm_histories');

    expect(fn () => app(SwarmRunner::class)->runQueued(FakeSequentialSwarm::make(), 'legacy-queue-task'))
        ->toThrow(LostSwarmLeaseException::class, 'Database-backed queued swarms require [execution_token] and [leased_until] columns on the history table.');
});

test('database-backed assert persisted finds structured and callable matches beyond the latest 100 runs', function () {
    foreach (range(1, 101) as $index) {
        FakeSequentialSwarm::make()->run(['draft_id' => $index]);
    }

    expect(function (): void {
        FakeSequentialSwarm::assertPersisted(['draft_id' => 101]);
        FakeSequentialSwarm::assertPersisted(fn (array $run): bool => ($run['context']['data']['draft_id'] ?? null) === 101);
    })->not->toThrow(AssertionFailedError::class);
});

test('database-backed assert persisted uses explicit input data and metadata matching rules', function () {
    FakeSequentialSwarm::make()->run(RunContext::from([
        'input' => 'Draft outline',
        'data' => ['draft_id' => 42],
        'metadata' => ['campaign' => 'content-calendar'],
    ]));

    expect(function (): void {
        FakeSequentialSwarm::assertPersisted(['input' => 'Draft outline']);
        FakeSequentialSwarm::assertPersisted(['draft_id' => 42]);
        FakeSequentialSwarm::assertPersisted(['metadata' => ['campaign' => 'content-calendar']]);
    })->not->toThrow(AssertionFailedError::class);
});
