<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
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

class DatabaseInvalidPayloadValue
{
    public string $value = 'sensitive';
}

function insertPrunableBranchRow(string $runId, string $branchId, Carbon $expiresAt, string $status = 'completed'): void
{
    DB::table('swarm_durable_branches')->insert([
        'run_id' => $runId,
        'branch_id' => $branchId,
        'step_index' => 0,
        'node_id' => null,
        'agent_class' => FakeResearcher::class,
        'parent_node_id' => 'parallel',
        'status' => $status,
        'input' => 'input',
        'output' => 'output',
        'usage' => json_encode([]),
        'metadata' => json_encode([]),
        'failure' => null,
        'duration_ms' => 1,
        'execution_token' => null,
        'lease_acquired_at' => null,
        'leased_until' => null,
        'attempts' => 0,
        'queue_connection' => null,
        'queue_name' => null,
        'started_at' => null,
        'finished_at' => $status === 'completed' ? $expiresAt : null,
        'expires_at' => $expiresAt,
        'created_at' => $expiresAt,
        'updated_at' => $expiresAt,
    ]);
}

beforeEach(function () {
    config()->set('database.default', 'testing');
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('database migrations add composite indexes for durable recovery scans', function () {
    expect(Schema::hasIndex('swarm_durable_runs', 'swarm_durable_runs_recovery_idx'))->toBeTrue()
        ->and(Schema::hasIndex('swarm_durable_runs', 'swarm_durable_runs_waiting_join_idx'))->toBeTrue()
        ->and(Schema::hasIndex('swarm_durable_branches', 'swarm_durable_branches_recovery_idx'))->toBeTrue();
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

    $context->mergeMetadata(['updated' => true]);
    $store->put($context, 120);

    expect($store->find('context-run-id'))->toBe($context->toArray());
    expect(DB::table('swarm_contexts')->where('run_id', 'context-run-id')->count())->toBe(1);
});

test('database context store persists long task inputs', function () {
    $store = app(DatabaseContextStore::class);
    $longInput = str_repeat('Laravel Swarm long prompt. ', 4000);
    $context = RunContext::from($longInput, 'long-context-run-id');

    $store->put($context, 60);

    expect($store->find('long-context-run-id')['input'])->toBe($longInput);
});

test('database context store rejects invalid context data before persistence', function () {
    $store = app(DatabaseContextStore::class);
    $context = new RunContext('invalid-context-data-run-id', 'database-task', data: ['bad' => new DatabaseInvalidPayloadValue]);

    expect(fn () => $store->put($context, 60))
        ->toThrow(SwarmException::class, 'Swarm plain data value [RunContext.data.bad] must be a string, integer, float, boolean, null, or array of plain data.');

    expect(DB::table('swarm_contexts')->where('run_id', 'invalid-context-data-run-id')->exists())->toBeFalse();
});

test('database context store rejects invalid context metadata before persistence', function () {
    $store = app(DatabaseContextStore::class);
    $context = new RunContext('invalid-context-metadata-run-id', 'database-task', metadata: ['bad' => new DatabaseInvalidPayloadValue]);

    expect(fn () => $store->put($context, 60))
        ->toThrow(SwarmException::class, 'Swarm plain data value [RunContext.metadata.bad] must be a string, integer, float, boolean, null, or array of plain data.');

    expect(DB::table('swarm_contexts')->where('run_id', 'invalid-context-metadata-run-id')->exists())->toBeFalse();
});

test('database context store rejects invalid artifact content before persistence', function () {
    $store = app(DatabaseContextStore::class);
    $context = new RunContext('invalid-context-artifact-content-run-id', 'database-task');
    $context->addArtifact(new SwarmArtifact('manual', new DatabaseInvalidPayloadValue));

    expect(fn () => $store->put($context, 60))
        ->toThrow(SwarmException::class, 'Swarm plain data value [RunContext.artifacts.0.content] must be a string, integer, float, boolean, null, or array of plain data.');

    expect(DB::table('swarm_contexts')->where('run_id', 'invalid-context-artifact-content-run-id')->exists())->toBeFalse();
});

test('database context store rejects invalid artifact metadata before persistence', function () {
    $store = app(DatabaseContextStore::class);
    $context = new RunContext('invalid-context-artifact-metadata-run-id', 'database-task');
    $context->addArtifact(new SwarmArtifact('manual', 'content', ['bad' => new DatabaseInvalidPayloadValue]));

    expect(fn () => $store->put($context, 60))
        ->toThrow(SwarmException::class, 'Swarm plain data value [RunContext.artifacts.0.metadata.bad] must be a string, integer, float, boolean, null, or array of plain data.');

    expect(DB::table('swarm_contexts')->where('run_id', 'invalid-context-artifact-metadata-run-id')->exists())->toBeFalse();
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

test('prune removes cancelled history rows and terminal durable runtime rows', function () {
    $expired = Carbon::now('UTC')->subMinute();
    $future = Carbon::now('UTC')->addHour();

    foreach ([
        ['run_id' => 'cancelled-run', 'status' => 'cancelled', 'expires_at' => $expired],
        ['run_id' => 'active-paused-run', 'status' => 'paused', 'expires_at' => $expired],
        ['run_id' => 'future-cancelled-run', 'status' => 'cancelled', 'expires_at' => $future],
    ] as $row) {
        DB::table('swarm_run_histories')->insert([
            'run_id' => $row['run_id'],
            'swarm_class' => 'ExampleSwarm',
            'topology' => 'sequential',
            'status' => $row['status'],
            'context' => json_encode([]),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'output' => null,
            'usage' => json_encode([]),
            'error' => null,
            'artifacts' => json_encode([]),
            'finished_at' => $row['status'] === 'cancelled' ? $expired : null,
            'expires_at' => $row['expires_at'],
            'execution_token' => null,
            'leased_until' => null,
            'created_at' => $expired,
            'updated_at' => $expired,
        ]);

        DB::table('swarm_contexts')->insert([
            'run_id' => $row['run_id'],
            'input' => 'input',
            'data' => json_encode([]),
            'metadata' => json_encode([]),
            'artifacts' => json_encode([]),
            'expires_at' => $expired,
            'created_at' => $expired,
            'updated_at' => $expired,
        ]);

        DB::table('swarm_artifacts')->insert([
            'run_id' => $row['run_id'],
            'name' => 'agent_output',
            'content' => json_encode('output'),
            'metadata' => json_encode([]),
            'step_agent_class' => null,
            'expires_at' => $expired,
            'created_at' => $expired,
            'updated_at' => $expired,
        ]);

        DB::table('swarm_durable_runs')->insert([
            'run_id' => $row['run_id'],
            'swarm_class' => 'ExampleSwarm',
            'topology' => 'sequential',
            'status' => $row['status'],
            'next_step_index' => 0,
            'current_step_index' => null,
            'total_steps' => 1,
            'timeout_at' => $future,
            'step_timeout_seconds' => 300,
            'execution_token' => null,
            'leased_until' => null,
            'pause_requested_at' => $row['status'] === 'paused' ? $expired : null,
            'cancel_requested_at' => $row['status'] === 'cancelled' ? $expired : null,
            'queue_connection' => null,
            'queue_name' => null,
            'finished_at' => $row['status'] === 'cancelled' ? $expired : null,
            'created_at' => $expired,
            'updated_at' => $expired,
        ]);
    }

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_run_histories')->where('run_id', 'cancelled-run')->exists())->toBeFalse();
    expect(DB::table('swarm_contexts')->where('run_id', 'cancelled-run')->exists())->toBeFalse();
    expect(DB::table('swarm_artifacts')->where('run_id', 'cancelled-run')->exists())->toBeFalse();
    expect(DB::table('swarm_durable_runs')->where('run_id', 'cancelled-run')->exists())->toBeFalse();
    expect(DB::table('swarm_run_histories')->where('run_id', 'active-paused-run')->exists())->toBeTrue();
    expect(DB::table('swarm_contexts')->where('run_id', 'active-paused-run')->exists())->toBeTrue();
    expect(DB::table('swarm_artifacts')->where('run_id', 'active-paused-run')->exists())->toBeTrue();
    expect(DB::table('swarm_durable_runs')->where('run_id', 'active-paused-run')->exists())->toBeTrue();
    expect(DB::table('swarm_durable_runs')->where('run_id', 'future-cancelled-run')->exists())->toBeTrue();
});

test('prune removes expired durable branch rows while preserving active runs', function () {
    $expired = Carbon::now('UTC')->subMinute();
    $future = Carbon::now('UTC')->addHour();

    foreach ([
        ['run_id' => 'terminal-branch-run', 'status' => 'completed', 'finished_at' => $expired],
        ['run_id' => 'waiting-branch-run', 'status' => 'waiting', 'finished_at' => null],
    ] as $row) {
        DB::table('swarm_run_histories')->insert([
            'run_id' => $row['run_id'],
            'swarm_class' => 'ExampleSwarm',
            'topology' => 'parallel',
            'status' => $row['status'],
            'context' => json_encode([]),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'output' => null,
            'usage' => json_encode([]),
            'error' => null,
            'artifacts' => json_encode([]),
            'finished_at' => $row['finished_at'],
            'expires_at' => $expired,
            'execution_token' => null,
            'leased_until' => null,
            'created_at' => $expired,
            'updated_at' => $expired,
        ]);

        DB::table('swarm_durable_runs')->insert([
            'run_id' => $row['run_id'],
            'swarm_class' => 'ExampleSwarm',
            'topology' => 'parallel',
            'status' => $row['status'],
            'next_step_index' => 1,
            'current_step_index' => null,
            'current_node_id' => $row['status'] === 'waiting' ? 'parallel' : null,
            'total_steps' => 1,
            'timeout_at' => $future,
            'step_timeout_seconds' => 300,
            'execution_token' => null,
            'leased_until' => null,
            'pause_requested_at' => null,
            'cancel_requested_at' => null,
            'queue_connection' => null,
            'queue_name' => null,
            'finished_at' => $row['finished_at'],
            'created_at' => $expired,
            'updated_at' => $expired,
        ]);
    }

    insertPrunableBranchRow('terminal-branch-run', 'parallel:0', $expired);
    insertPrunableBranchRow('waiting-branch-run', 'parallel:0', $expired, 'pending');

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_durable_branches')->where('run_id', 'terminal-branch-run')->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_branches')->where('run_id', 'waiting-branch-run')->exists())->toBeTrue();
});

test('prune silently skips the configured durable branch table when it is missing', function () {
    Schema::dropIfExists('swarm_durable_branches');

    Artisan::call('swarm:prune');

    expect(Artisan::output())->not->toContain('Skipping durable_branches pruning');
});

test('swarm prune skips safely when package tables are missing', function () {
    Schema::dropIfExists('swarm_artifacts');
    Schema::dropIfExists('swarm_contexts');
    Schema::dropIfExists('swarm_durable_runs');
    Schema::dropIfExists('swarm_run_histories');

    Artisan::call('swarm:prune');

    expect(Artisan::output())->toContain('Skipping swarm pruning because history table [swarm_run_histories] does not exist.');
});

test('swarm prune does not delete supporting rows when history table is missing', function () {
    $expired = Carbon::now('UTC')->subMinute();
    $future = Carbon::now('UTC')->addHour();

    Schema::dropIfExists('swarm_run_histories');

    DB::table('swarm_contexts')->insert([
        'run_id' => 'orphan-context',
        'input' => 'input',
        'data' => json_encode([]),
        'metadata' => json_encode([]),
        'artifacts' => json_encode([]),
        'expires_at' => $expired,
        'created_at' => $expired,
        'updated_at' => $expired,
    ]);
    DB::table('swarm_artifacts')->insert([
        'run_id' => 'orphan-artifact',
        'name' => 'agent_output',
        'content' => json_encode('output'),
        'metadata' => json_encode([]),
        'step_agent_class' => null,
        'expires_at' => $expired,
        'created_at' => $expired,
        'updated_at' => $expired,
    ]);
    DB::table('swarm_durable_runs')->insert([
        'run_id' => 'orphan-durable',
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'completed',
        'next_step_index' => 1,
        'current_step_index' => null,
        'total_steps' => 1,
        'timeout_at' => $future,
        'step_timeout_seconds' => 300,
        'execution_token' => null,
        'leased_until' => null,
        'pause_requested_at' => null,
        'cancel_requested_at' => null,
        'queue_connection' => null,
        'queue_name' => null,
        'finished_at' => $expired,
        'created_at' => $expired,
        'updated_at' => $expired,
    ]);

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_contexts')->where('run_id', 'orphan-context')->exists())->toBeTrue();
    expect(DB::table('swarm_artifacts')->where('run_id', 'orphan-artifact')->exists())->toBeTrue();
    expect(DB::table('swarm_durable_runs')->where('run_id', 'orphan-durable')->exists())->toBeTrue();
});

test('swarm prune skips missing optional tables and prunes present tables', function () {
    $expired = Carbon::now('UTC')->subMinute();

    Schema::dropIfExists('swarm_contexts');

    DB::table('swarm_run_histories')->insert([
        'run_id' => 'expired-history-with-missing-context',
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'completed',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => 'output',
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => $expired,
        'expires_at' => $expired,
        'execution_token' => null,
        'leased_until' => null,
        'created_at' => $expired,
        'updated_at' => $expired,
    ]);
    DB::table('swarm_artifacts')->insert([
        'run_id' => 'expired-history-with-missing-context',
        'name' => 'agent_output',
        'content' => json_encode('output'),
        'metadata' => json_encode([]),
        'step_agent_class' => null,
        'expires_at' => $expired,
        'created_at' => $expired,
        'updated_at' => $expired,
    ]);

    Artisan::call('swarm:prune');

    expect(Artisan::output())->toContain('Skipping contexts pruning because table [swarm_contexts] does not exist.');
    expect(DB::table('swarm_run_histories')->where('run_id', 'expired-history-with-missing-context')->exists())->toBeFalse();
    expect(DB::table('swarm_artifacts')->where('run_id', 'expired-history-with-missing-context')->exists())->toBeFalse();
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
    expect(DB::table('swarm_run_steps')->where('run_id', 'history-run-id')->count())->toBe(1);
    expect(json_decode(DB::table('swarm_run_histories')->where('run_id', 'history-run-id')->value('steps'), true))->toBe([]);
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

test('database run history store reads legacy inline steps when normalized rows are absent', function () {
    $now = Carbon::now('UTC');
    $legacyStep = [
        'agent_class' => FakeEditor::class,
        'input' => 'legacy-input',
        'output' => 'legacy-output',
        'artifacts' => [],
        'metadata' => ['index' => 0],
    ];

    DB::table('swarm_run_histories')->insert([
        'run_id' => 'legacy-steps-run-id',
        'swarm_class' => FakeSequentialSwarm::class,
        'topology' => 'sequential',
        'status' => 'completed',
        'context' => json_encode(RunContext::from('legacy-input', 'legacy-steps-run-id')->toArray()),
        'metadata' => json_encode([]),
        'steps' => json_encode([$legacyStep]),
        'output' => 'legacy-output',
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => $now,
        'expires_at' => $now->copy()->addMinute(),
        'execution_token' => null,
        'leased_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(app(DatabaseRunHistoryStore::class)->find('legacy-steps-run-id')['steps'])->toBe([$legacyStep]);
});

test('database run history store merges legacy inline steps with normalized step rows', function () {
    $now = Carbon::now('UTC');
    $legacyStep = [
        'agent_class' => FakeResearcher::class,
        'input' => 'legacy-input',
        'output' => 'legacy-output',
        'artifacts' => [],
        'metadata' => ['index' => 0],
    ];
    $staleInlineStep = [
        'agent_class' => FakeWriter::class,
        'input' => 'stale-input',
        'output' => 'stale-output',
        'artifacts' => [],
        'metadata' => ['index' => 1],
    ];
    $normalizedStep = [
        'agent_class' => FakeEditor::class,
        'input' => 'normalized-input',
        'output' => 'normalized-output',
        'artifacts' => [],
        'metadata' => ['index' => 1],
    ];

    DB::table('swarm_run_histories')->insert([
        'run_id' => 'mixed-steps-run-id',
        'swarm_class' => FakeSequentialSwarm::class,
        'topology' => 'sequential',
        'status' => 'completed',
        'context' => json_encode(RunContext::from('legacy-input', 'mixed-steps-run-id')->toArray()),
        'metadata' => json_encode([]),
        'steps' => json_encode([$legacyStep, $staleInlineStep]),
        'output' => 'normalized-output',
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => $now,
        'expires_at' => $now->copy()->addMinute(),
        'execution_token' => null,
        'leased_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('swarm_run_steps')->insert([
        'run_id' => 'mixed-steps-run-id',
        'step_index' => 1,
        'agent_class' => $normalizedStep['agent_class'],
        'input' => $normalizedStep['input'],
        'output' => $normalizedStep['output'],
        'artifacts' => json_encode($normalizedStep['artifacts']),
        'metadata' => json_encode($normalizedStep['metadata']),
        'expires_at' => $now->copy()->addMinute(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(app(DatabaseRunHistoryStore::class)->find('mixed-steps-run-id')['steps'])->toBe([
        $legacyStep,
        $normalizedStep,
    ]);
});

test('database run history store idempotently upserts duplicate normalized steps', function () {
    $history = app(DatabaseRunHistoryStore::class);
    $context = RunContext::from('atomic-history-task', 'atomic-history-run-id');

    $history->start('atomic-history-run-id', 'ExampleSwarm', 'sequential', $context, [], 60);
    $history->recordStep('atomic-history-run-id', new SwarmStep(
        agentClass: FakeEditor::class,
        input: 'atomic-history-task',
        output: 'first-output',
        metadata: ['index' => 0],
    ), 60);

    $history->recordStep('atomic-history-run-id', new SwarmStep(
        agentClass: FakeWriter::class,
        input: 'atomic-history-task',
        output: 'duplicate-index-output',
        metadata: ['index' => 0],
    ), 3600);

    expect(DB::table('swarm_run_steps')->where('run_id', 'atomic-history-run-id')->count())->toBe(1);
    expect(DB::table('swarm_run_steps')->where('run_id', 'atomic-history-run-id')->value('output'))->toBe('duplicate-index-output');
});

test('database run history store requires explicit integer indexes for normalized steps', function () {
    $history = app(DatabaseRunHistoryStore::class);
    $context = RunContext::from('missing-index-task', 'missing-index-run-id');

    $history->start('missing-index-run-id', 'ExampleSwarm', 'sequential', $context, [], 60);

    expect(fn () => $history->recordStep('missing-index-run-id', new SwarmStep(
        agentClass: FakeEditor::class,
        input: 'missing-index-task',
        output: 'first-output',
    ), 60))->toThrow(SwarmException::class, 'Normalized database run history steps require an integer [index] metadata value.');
});

test('database run history store redacts failure messages when capture is disabled', function () {
    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);

    $history = app(DatabaseRunHistoryStore::class);
    $context = RunContext::from('history-task', 'redacted-failure-run-id');

    $history->start('redacted-failure-run-id', 'ExampleSwarm', 'sequential', $context, [], 60);
    $history->fail('redacted-failure-run-id', new Exception('sensitive provider payload'), 60);

    expect($history->find('redacted-failure-run-id')['error'])->toBe([
        'message' => '[redacted]',
        'class' => Exception::class,
    ]);
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

    Schema::create('custom_swarm_history_steps', function (Blueprint $table): void {
        $table->id();
        $table->string('run_id')->index();
        $table->unsignedInteger('step_index');
        $table->string('agent_class');
        $table->longText('input');
        $table->longText('output');
        $table->json('artifacts');
        $table->json('metadata');
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->unique(['run_id', 'step_index']);
    });

    config()->set('swarm.tables.contexts', 'custom_swarm_contexts');
    config()->set('swarm.tables.artifacts', 'custom_swarm_artifacts');
    config()->set('swarm.tables.history', 'custom_swarm_histories');
    config()->set('swarm.tables.history_steps', 'custom_swarm_history_steps');

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
    $historyStore->recordStep('custom-table-run', new SwarmStep(
        agentClass: FakeEditor::class,
        input: 'custom-table-task',
        output: 'custom-step-output',
        metadata: ['index' => 0],
    ), 60);

    expect($contextStore->find('custom-table-run')['input'])->toBe('custom-table-task');
    expect($artifactRepository->all('custom-table-run')[0]['content'])->toBe('custom-artifact');
    expect($historyStore->find('custom-table-run')['status'])->toBe('running');
    expect($historyStore->find('custom-table-run')['steps'][0]['output'])->toBe('custom-step-output');
    expect(DB::table('custom_swarm_history_steps')->where('run_id', 'custom-table-run')->count())->toBe(1);
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

    DB::table('swarm_run_steps')->insert([
        [
            'run_id' => 'expired-history',
            'step_index' => 0,
            'agent_class' => FakeEditor::class,
            'input' => 'expired',
            'output' => 'expired',
            'artifacts' => json_encode([]),
            'metadata' => json_encode(['index' => 0]),
            'expires_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'run_id' => 'active-history',
            'step_index' => 0,
            'agent_class' => FakeEditor::class,
            'input' => 'active',
            'output' => 'active',
            'artifacts' => json_encode([]),
            'metadata' => json_encode(['index' => 0]),
            'expires_at' => $now->copy()->addMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'run_id' => 'running-history',
            'step_index' => 0,
            'agent_class' => FakeEditor::class,
            'input' => 'running',
            'output' => 'running',
            'artifacts' => json_encode([]),
            'metadata' => json_encode(['index' => 0]),
            'expires_at' => $now->copy()->subMinute(),
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
    expect(DB::table('swarm_run_steps')->where('run_id', 'expired-history')->exists())->toBeFalse();
    expect(DB::table('swarm_run_steps')->where('run_id', 'active-history')->exists())->toBeTrue();
    expect(DB::table('swarm_run_steps')->where('run_id', 'running-history')->exists())->toBeTrue();
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
