<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Exceptions\MissingQueueLeaseSchemaException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Contracts\Encryption\DecryptException;
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

function insertMinimalHistoryRow(string $runId, string $status = 'completed', ?Carbon $expiresAt = null, ?Carbon $finishedAt = null): void
{
    $now = Carbon::now('UTC');
    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => $status,
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => $finishedAt ?? ($status === 'completed' ? $now : null),
        'expires_at' => $expiresAt ?? $now->copy()->addHour(),
        'execution_token' => null,
        'leased_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
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

test('durable runs split migration adds side state tables and removes wide json columns from swarm_durable_runs', function () {
    expect(Schema::hasTable('swarm_durable_node_states'))->toBeTrue()
        ->and(Schema::hasTable('swarm_durable_run_state'))->toBeTrue()
        ->and(Schema::hasColumn('swarm_durable_runs', 'route_plan'))->toBeFalse()
        ->and(Schema::hasColumn('swarm_durable_runs', 'node_states'))->toBeFalse()
        ->and(Schema::hasColumn('swarm_durable_runs', 'failure'))->toBeFalse()
        ->and(Schema::hasColumn('swarm_durable_runs', 'retry_policy'))->toBeFalse();
});

test('database context store persists the same context shape as cache', function () {
    insertMinimalHistoryRow('context-run-id');
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
    insertMinimalHistoryRow('long-context-run-id');
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
    insertMinimalHistoryRow('artifact-run-id');
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

test('prune dry-run reports would-delete counts without deleting rows', function () {
    $expired = Carbon::now('UTC')->subMinute();
    $future = Carbon::now('UTC')->addHour();

    DB::table('swarm_run_histories')->insert([
        'run_id' => 'dry-run-cancelled',
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'cancelled',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
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

    DB::table('swarm_contexts')->insert([
        'run_id' => 'dry-run-cancelled',
        'input' => 'input',
        'data' => json_encode([]),
        'metadata' => json_encode([]),
        'artifacts' => json_encode([]),
        'expires_at' => $expired,
        'created_at' => $expired,
        'updated_at' => $expired,
    ]);

    DB::table('swarm_artifacts')->insert([
        'run_id' => 'dry-run-cancelled',
        'name' => 'agent_output',
        'content' => json_encode('output'),
        'metadata' => json_encode([]),
        'step_agent_class' => null,
        'expires_at' => $expired,
        'created_at' => $expired,
        'updated_at' => $expired,
    ]);

    DB::table('swarm_durable_runs')->insert([
        'run_id' => 'dry-run-cancelled',
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'cancelled',
        'next_step_index' => 0,
        'current_step_index' => null,
        'total_steps' => 1,
        'timeout_at' => $future,
        'step_timeout_seconds' => 300,
        'execution_token' => null,
        'leased_until' => null,
        'pause_requested_at' => null,
        'cancel_requested_at' => $expired,
        'queue_connection' => null,
        'queue_name' => null,
        'finished_at' => $expired,
        'created_at' => $expired,
        'updated_at' => $expired,
    ]);

    $historyBefore = DB::table('swarm_run_histories')->count();

    Artisan::call('swarm:prune', ['--dry-run' => true]);

    $output = Artisan::output();

    expect($output)->toContain('Would prune')
        ->and($output)->toContain('1 history')
        ->and($output)->toContain('1 context')
        ->and($output)->toContain('1 artifact');
    expect(DB::table('swarm_run_histories')->count())->toBe($historyBefore);
    expect(DB::table('swarm_run_histories')->where('run_id', 'dry-run-cancelled')->exists())->toBeTrue();
});

test('prune exits without deleting when retention.prevent_prune is enabled', function () {
    config(['swarm.retention.prevent_prune' => true]);

    $expired = Carbon::now('UTC')->subMinute();

    DB::table('swarm_run_histories')->insert([
        'run_id' => 'prevent-prune-run',
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'cancelled',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
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

    Artisan::call('swarm:prune');

    $output = Artisan::output();

    expect($output)->toContain('disabled')
        ->and($output)->toContain('prevent_prune');
    expect(DB::table('swarm_run_histories')->where('run_id', 'prevent-prune-run')->exists())->toBeTrue();

    config(['swarm.retention.prevent_prune' => false]);
});

test('prune dry-run still runs when retention.prevent_prune is enabled', function () {
    config(['swarm.retention.prevent_prune' => true]);

    $expired = Carbon::now('UTC')->subMinute();

    DB::table('swarm_run_histories')->insert([
        'run_id' => 'prevent-prune-dry-run',
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'cancelled',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
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

    Artisan::call('swarm:prune', ['--dry-run' => true]);

    $output = Artisan::output();

    expect($output)->toContain('Would prune')->not->toContain('disabled');
    expect(DB::table('swarm_run_histories')->where('run_id', 'prevent-prune-dry-run')->exists())->toBeTrue();

    config(['swarm.retention.prevent_prune' => false]);
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

    // Disable FK enforcement while setting up a degraded-schema fixture:
    // SQLite 3.26+ would cascade-delete child rows when the parent table is
    // dropped while FK enforcement is ON.  We disable it here to faithfully
    // simulate rows that existed before the parent table was torn down.
    DB::statement('PRAGMA foreign_keys = OFF');

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

    Schema::dropIfExists('swarm_run_histories');

    DB::statement('PRAGMA foreign_keys = ON');
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

    // Parent history rows for context and artifact run IDs that are not covered
    // by the history batch below; these are completed rows so they do not block
    // context/artifact pruning via the active-status guard.
    insertMinimalHistoryRow('expired-context', 'completed', $now->copy()->subMinute(), $now->copy()->subMinute());
    insertMinimalHistoryRow('active-context', 'completed', $now->copy()->addMinute(), $now->copy()->subMinute());
    insertMinimalHistoryRow('expired-artifact', 'completed', $now->copy()->subMinute(), $now->copy()->subMinute());
    insertMinimalHistoryRow('active-artifact', 'completed', $now->copy()->addMinute(), $now->copy()->subMinute());

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
        ->toThrow(MissingQueueLeaseSchemaException::class, 'Database-backed queued swarms require [execution_token] and [leased_until] columns on the history table.');
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

test('database context store seals persisted input when encrypt at rest is enabled', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(ContextStore::class);

    $store = app(ContextStore::class);
    $runId = (string) str()->uuid();

    insertMinimalHistoryRow($runId, 'running');

    $store->put(RunContext::from('classified-prompt', $runId), 3600);

    $raw = DB::table('swarm_contexts')->where('run_id', $runId)->value('input');
    expect($raw)->toStartWith('sw0:');

    expect($store->find($runId)['input'])->toBe('classified-prompt');
});

test('stream step checkpoint store seals output when encrypt at rest is enabled (#202)', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(StreamStepCheckpointStore::class);
    app()->forgetInstance(SwarmPersistenceCipher::class);

    $store = app(StreamStepCheckpointStore::class);
    $runId = (string) str()->uuid();

    insertMinimalHistoryRow($runId, 'running');

    $store->record($runId, 0, 'classified-step-output', ['prompt_tokens' => 3]);

    // The raw column is ciphertext, matching swarm_contexts.input / run_steps.output.
    $raw = DB::table('swarm_stream_step_checkpoints')->where('run_id', $runId)->value('output');
    expect($raw)->toStartWith('sw0:');

    // find() opens it back to the byte-identical plaintext (resume stays exact).
    $checkpoint = $store->find($runId, 0);
    expect($checkpoint->output)->toBe('classified-step-output');
    expect($checkpoint->usage)->toBe(['prompt_tokens' => 3]);
});

test('stream step checkpoint store stores plaintext output when encrypt at rest is disabled (#202)', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', false);
    app()->forgetInstance(StreamStepCheckpointStore::class);
    app()->forgetInstance(SwarmPersistenceCipher::class);

    $store = app(StreamStepCheckpointStore::class);
    $runId = (string) str()->uuid();

    insertMinimalHistoryRow($runId, 'running');

    $store->record($runId, 0, 'plain-step-output', []);

    $raw = DB::table('swarm_stream_step_checkpoints')->where('run_id', $runId)->value('output');
    expect($raw)->toBe('plain-step-output');
    expect($store->find($runId, 0)->output)->toBe('plain-step-output');
});

test('stream step checkpoint store treats an undecryptable output as absent so the step re-executes (#202)', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(StreamStepCheckpointStore::class);
    app()->forgetInstance(SwarmPersistenceCipher::class);

    $store = app(StreamStepCheckpointStore::class);
    $runId = (string) str()->uuid();

    insertMinimalHistoryRow($runId, 'running');

    // A sealed value this APP_KEY cannot decrypt (rotated/wrong key). The
    // is_string completion-marker check passes on the ciphertext, but find()
    // must NOT hand back a checkpoint whose output decrypts to null/garbage —
    // it returns null so the runner re-executes the step rather than feeding an
    // empty/wrong prompt downstream.
    DB::table('swarm_stream_step_checkpoints')->insert([
        'run_id' => $runId,
        'step_index' => 0,
        'output' => 'sw0:'.base64_encode('not-real-ciphertext'),
        'usage' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($store->find($runId, 0))->toBeNull();
});

test('stream step checkpoint store round-trips an output that legitimately starts with the sealed prefix (#202)', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(StreamStepCheckpointStore::class);
    app()->forgetInstance(SwarmPersistenceCipher::class);

    $store = app(StreamStepCheckpointStore::class);
    $runId = (string) str()->uuid();

    insertMinimalHistoryRow($runId, 'running');

    // An agent output that itself begins with the cipher's `sw0:` sentinel. It is
    // sealed on write and must decrypt cleanly on read — find() must NOT mistake the
    // decrypted plaintext's leading bytes for a "still sealed / undecryptable" value
    // and re-execute. (This is the false-negative the round-3 prefix heuristic had.)
    $store->record($runId, 0, 'sw0:hello', ['prompt_tokens' => 2]);

    $checkpoint = $store->find($runId, 0);
    expect($checkpoint)->not->toBeNull();
    expect($checkpoint->output)->toBe('sw0:hello');
    expect($checkpoint->usage)->toBe(['prompt_tokens' => 2]);
});

test('stream step checkpoint store re-executes (not surfaces ciphertext) on an undecryptable output under the legacy policy (#202)', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    // The `legacy` display policy makes open() surface stored ciphertext on a
    // decrypt failure. find() uses the policy-INDEPENDENT openStrict(), so an
    // undecryptable checkpoint still reads as absent (→ re-execute) and never
    // feeds the sealed value downstream.
    config()->set('swarm.persistence.decrypt_failure_policy', 'legacy');
    app()->forgetInstance(StreamStepCheckpointStore::class);
    app()->forgetInstance(SwarmPersistenceCipher::class);

    $store = app(StreamStepCheckpointStore::class);
    $runId = (string) str()->uuid();

    insertMinimalHistoryRow($runId, 'running');

    DB::table('swarm_stream_step_checkpoints')->insert([
        'run_id' => $runId,
        'step_index' => 0,
        'output' => 'sw0:'.base64_encode('not-real-ciphertext'),
        'usage' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($store->find($runId, 0))->toBeNull();
});

test('stream step checkpoint store round-trips an empty-string output (completed step) under encryption (#202)', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(StreamStepCheckpointStore::class);
    app()->forgetInstance(SwarmPersistenceCipher::class);

    $store = app(StreamStepCheckpointStore::class);
    $runId = (string) str()->uuid();

    insertMinimalHistoryRow($runId, 'running');

    // An empty output is a legitimately-completed step (seal('') short-circuits
    // to '', openStrict('') returns ''), so find() must return a checkpoint with
    // output '' — NOT treat it as absent/undecryptable.
    $store->record($runId, 0, '', ['prompt_tokens' => 1]);

    $checkpoint = $store->find($runId, 0);
    expect($checkpoint)->not->toBeNull();
    expect($checkpoint->output)->toBe('');
});

test('stream step checkpoint store treats a prefix-only sealed value as absent (#202)', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(StreamStepCheckpointStore::class);
    app()->forgetInstance(SwarmPersistenceCipher::class);

    $store = app(StreamStepCheckpointStore::class);
    $runId = (string) str()->uuid();

    insertMinimalHistoryRow($runId, 'running');

    // A bare 'sw0:' prefix with empty ciphertext can never decrypt — openStrict()
    // throws and the step re-executes (find() returns null), never feeding the
    // sentinel downstream.
    DB::table('swarm_stream_step_checkpoints')->insert([
        'run_id' => $runId,
        'step_index' => 0,
        'output' => 'sw0:',
        'usage' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($store->find($runId, 0))->toBeNull();
});

// --- #212: durable resume reads decrypt strictly + fail loud (durable twin of #202) ---

function insertDurableRunRow(string $runId, string $status = 'running'): void
{
    $now = Carbon::now('UTC');
    DB::table('swarm_durable_runs')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'parallel',
        'status' => $status,
        'next_step_index' => 1,
        'current_step_index' => null,
        'current_node_id' => null,
        'total_steps' => 1,
        'timeout_at' => $now->copy()->addHour(),
        'step_timeout_seconds' => 300,
        'execution_token' => null,
        'leased_until' => null,
        'pause_requested_at' => null,
        'cancel_requested_at' => null,
        'queue_connection' => null,
        'queue_name' => null,
        'finished_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function insertDurableBranchRow(string $runId, string $branchId, string $input, ?string $output = null, string $status = 'completed'): void
{
    $now = Carbon::now('UTC');
    DB::table('swarm_durable_branches')->insert([
        'run_id' => $runId,
        'branch_id' => $branchId,
        'step_index' => 0,
        'node_id' => null,
        'agent_class' => FakeResearcher::class,
        'parent_node_id' => 'parallel',
        'status' => $status,
        'input' => $input,
        'output' => $output,
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
        'finished_at' => $now,
        'expires_at' => $now->copy()->addHour(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function insertDurableChildRunRow(string $parentRunId, string $childRunId, string $contextPayloadJson): void
{
    $now = Carbon::now('UTC');
    DB::table('swarm_durable_child_runs')->insert([
        'parent_run_id' => $parentRunId,
        'child_run_id' => $childRunId,
        'child_swarm_class' => 'App\\Swarms\\Child',
        'wait_name' => 'child:'.$childRunId,
        'context_payload' => $contextPayloadJson,
        'status' => 'pending',
        'output' => null,
        'failure' => null,
        'dispatched_at' => null,
        'terminal_event_dispatched_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// `sw0:`+base64 is a sealed value no APP_KEY can decrypt — the rotated/wrong-key case.
function undecryptableSealedValue(): string
{
    return 'sw0:'.base64_encode('not-real-ciphertext');
}

function freshDurableRunStore(): DatabaseDurableRunStore
{
    app()->forgetInstance(SwarmPersistenceCipher::class);

    return app(DatabaseDurableRunStore::class);
}

test('hierarchicalNodeOutputsFor fails loud with SwarmException on an undecryptable node output (#212)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $store = freshDurableRunStore();
    $runId = (string) str()->uuid();

    insertDurableRunRow($runId);
    DB::table('swarm_durable_node_outputs')->insert([
        'run_id' => $runId,
        'node_id' => 'researcher',
        'output' => undecryptableSealedValue(),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $store->hierarchicalNodeOutputsFor($runId, ['researcher']))
        ->toThrow(SwarmException::class, 'verify APP_KEY');
});

test('findBranch fails loud with SwarmException on an undecryptable branch input (#212)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $store = freshDurableRunStore();
    $runId = (string) str()->uuid();

    insertDurableRunRow($runId);
    insertDurableBranchRow($runId, 'parallel:researcher', undecryptableSealedValue());

    expect(fn () => $store->findBranch($runId, 'parallel:researcher'))
        ->toThrow(SwarmException::class, 'verify APP_KEY');
});

test('branchesFor fails loud with SwarmException on an undecryptable branch output (#212)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $store = freshDurableRunStore();
    $runId = (string) str()->uuid();

    insertDurableRunRow($runId);
    // input is plaintext (decryptable), output is the undecryptable sealed value —
    // proves branch OUTPUT is strict on the operational path too (it feeds the join).
    insertDurableBranchRow($runId, 'parallel:researcher', 'plain-input', undecryptableSealedValue());

    expect(fn () => $store->branchesFor($runId))
        ->toThrow(SwarmException::class, 'verify APP_KEY');
});

test('childRunForChild fails loud with SwarmException on an undecryptable context payload (#212)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $store = freshDurableRunStore();
    $parentRunId = (string) str()->uuid();
    $childRunId = (string) str()->uuid();

    insertDurableRunRow($parentRunId);
    // Only the nested `input` key is sealed (createChildRun seals via sealContextTopLevelInput).
    insertDurableChildRunRow($parentRunId, $childRunId, json_encode(['input' => undecryptableSealedValue()]));

    expect(fn () => $store->childRunForChild($childRunId))
        ->toThrow(SwarmException::class, 'verify APP_KEY');
});

test('inspector branch read honors decrypt_failure_policy on an undecryptable input (#212)', function (string $policy, $assert) {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.persistence.decrypt_failure_policy', $policy);
    $store = freshDurableRunStore();
    $runId = (string) str()->uuid();

    insertDurableRunRow($runId);
    insertDurableBranchRow($runId, 'parallel:researcher', undecryptableSealedValue());

    $assert($store, $runId);
})->with([
    'null_with_log returns null' => ['null_with_log', function (DatabaseDurableRunStore $store, string $runId) {
        expect($store->branchesForInspection($runId)[0]['input'])->toBeNull();
    }],
    'legacy surfaces ciphertext' => ['legacy', function (DatabaseDurableRunStore $store, string $runId) {
        expect($store->branchesForInspection($runId)[0]['input'])->toStartWith('sw0:');
    }],
    'throw rethrows the raw DecryptException' => ['throw', function (DatabaseDurableRunStore $store, string $runId) {
        expect(fn () => $store->branchesForInspection($runId))->toThrow(DecryptException::class);
    }],
]);

test('inspector child read honors null_with_log policy on an undecryptable context payload (#212)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.persistence.decrypt_failure_policy', 'null_with_log');
    $store = freshDurableRunStore();
    $parentRunId = (string) str()->uuid();
    $childRunId = (string) str()->uuid();

    insertDurableRunRow($parentRunId);
    insertDurableChildRunRow($parentRunId, $childRunId, json_encode(['input' => undecryptableSealedValue()]));

    expect($store->childRunsForInspection($parentRunId)[0]['context_payload']['input'])->toBeNull();
});

test('strict durable reads round-trip a value that legitimately starts with the sealed prefix (#212)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(SwarmPersistenceCipher::class);
    $cipher = app(SwarmPersistenceCipher::class);
    $store = app(DatabaseDurableRunStore::class);

    $runId = (string) str()->uuid();
    $childRunId = (string) str()->uuid();
    insertDurableRunRow($runId);

    // Each value is real plaintext that happens to begin with the `sw0:` sentinel,
    // sealed on write — the strict read must decrypt cleanly and NOT fail loud.
    DB::table('swarm_durable_node_outputs')->insert([
        'run_id' => $runId, 'node_id' => 'researcher',
        'output' => $cipher->seal('sw0:node'),
        'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    insertDurableBranchRow($runId, 'parallel:researcher', $cipher->seal('sw0:input'), $cipher->seal('sw0:output'));
    insertDurableChildRunRow($runId, $childRunId, json_encode(['input' => $cipher->seal('sw0:child')]));

    expect($store->hierarchicalNodeOutputsFor($runId, ['researcher'])['researcher'])->toBe('sw0:node');
    $branch = $store->findBranch($runId, 'parallel:researcher');
    expect($branch['input'])->toBe('sw0:input')
        ->and($branch['output'])->toBe('sw0:output');
    expect($store->childRunForChild($childRunId)['context_payload']['input'])->toBe('sw0:child');
});

test('strict and inspection reads on the same store instance do not interfere (#212 octane safety)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.persistence.decrypt_failure_policy', 'legacy');
    $store = freshDurableRunStore();
    $runId = (string) str()->uuid();

    insertDurableRunRow($runId);
    insertDurableBranchRow($runId, 'parallel:researcher', undecryptableSealedValue());

    // Strict op throws; the subsequent policy-aware inspection read on the SAME instance
    // still surfaces ciphertext under the legacy policy — the strict path never mutated
    // policy resolution (no shared mutable state).
    expect(fn () => $store->findBranch($runId, 'parallel:researcher'))->toThrow(SwarmException::class, 'verify APP_KEY');
    expect($store->branchesForInspection($runId)[0]['input'])->toStartWith('sw0:');
});

test('contextStore find fails loud with SwarmException on an undecryptable resume input (#212)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(SwarmPersistenceCipher::class);
    $store = app(DatabaseContextStore::class);
    $runId = (string) str()->uuid();

    insertMinimalHistoryRow($runId, 'running');
    DB::table('swarm_contexts')->insert([
        'run_id' => $runId,
        'input' => undecryptableSealedValue(),
        'data' => json_encode([]),
        'metadata' => json_encode([]),
        'artifacts' => json_encode([]),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $store->find($runId))->toThrow(SwarmException::class, 'verify APP_KEY');
});

test('contextStore find round-trips a sealed resume input that starts with the sealed prefix (#212)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(SwarmPersistenceCipher::class);
    $store = app(DatabaseContextStore::class);
    $runId = (string) str()->uuid();

    insertMinimalHistoryRow($runId, 'running');
    $store->put(RunContext::from('sw0:resume-me', $runId), 3600);

    expect($store->find($runId)['input'])->toBe('sw0:resume-me');
});

test('recoverableBranches tolerates one undecryptable row instead of aborting the cross-run sweep (#212 F1)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.persistence.decrypt_failure_policy', 'null_with_log');
    $store = freshDurableRunStore();
    $runId = (string) str()->uuid();
    insertDurableRunRow($runId);

    // A poison branch that matches the recovery criteria (pending, stale updated_at,
    // no retry/lease). The sweep reads only status/queue/ids, so it must NOT abort.
    $stale = now()->subHour();
    DB::table('swarm_durable_branches')->insert([
        'run_id' => $runId, 'branch_id' => 'parallel:researcher', 'step_index' => 0,
        'node_id' => null, 'agent_class' => FakeResearcher::class, 'parent_node_id' => 'parallel',
        'status' => 'pending', 'input' => undecryptableSealedValue(), 'output' => null,
        'usage' => json_encode([]), 'metadata' => json_encode([]), 'failure' => null,
        'duration_ms' => null, 'execution_token' => null, 'lease_acquired_at' => null,
        'leased_until' => null, 'attempts' => 0, 'queue_connection' => null, 'queue_name' => null,
        'started_at' => null, 'finished_at' => null, 'next_retry_at' => null,
        'expires_at' => now()->addHour(), 'created_at' => $stale, 'updated_at' => $stale,
    ]);

    $branches = $store->recoverableBranches();
    expect($branches)->toHaveCount(1)
        ->and($branches[0]['branch_id'])->toBe('parallel:researcher')
        ->and($branches[0]['input'])->toBeNull(); // non-strict → display policy → null, no throw
});

test('undispatchedChildRuns tolerates one undecryptable child instead of aborting the cross-run sweep (#212 F2)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.persistence.decrypt_failure_policy', 'null_with_log');
    $store = freshDurableRunStore();
    $parentRunId = (string) str()->uuid();
    $childRunId = (string) str()->uuid();
    insertDurableRunRow($parentRunId);
    insertDurableChildRunRow($parentRunId, $childRunId, json_encode(['input' => undecryptableSealedValue()]));

    $children = $store->undispatchedChildRuns();
    expect($children)->toHaveCount(1)
        ->and($children[0]['child_run_id'])->toBe($childRunId)
        ->and($children[0]['context_payload']['input'])->toBeNull(); // non-strict, no throw
});

test('dueRetryBranches tolerates one undecryptable row instead of aborting the cross-run sweep (#212 T5)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.persistence.decrypt_failure_policy', 'null_with_log');
    $store = freshDurableRunStore();
    $runId = (string) str()->uuid();
    insertDurableRunRow($runId);

    // Poison branch matching the due-retry criteria (pending, next_retry_at due, no lease).
    DB::table('swarm_durable_branches')->insert([
        'run_id' => $runId, 'branch_id' => 'parallel:researcher', 'step_index' => 0,
        'node_id' => null, 'agent_class' => FakeResearcher::class, 'parent_node_id' => 'parallel',
        'status' => 'pending', 'input' => undecryptableSealedValue(), 'output' => null,
        'usage' => json_encode([]), 'metadata' => json_encode([]), 'failure' => null,
        'duration_ms' => null, 'execution_token' => null, 'lease_acquired_at' => null,
        'leased_until' => null, 'attempts' => 1, 'queue_connection' => null, 'queue_name' => null,
        'started_at' => null, 'finished_at' => null, 'next_retry_at' => now()->subMinute(),
        'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $branches = $store->dueRetryBranches();
    expect($branches)->toHaveCount(1)
        ->and($branches[0]['branch_id'])->toBe('parallel:researcher')
        ->and($branches[0]['input'])->toBeNull(); // non-strict, no throw
});

test('childRuns is non-strict so reconcile/cancel never throw on a wrong-key child (#212 B-LOW)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.persistence.decrypt_failure_policy', 'null_with_log');
    $store = freshDurableRunStore();
    $parentRunId = (string) str()->uuid();
    $childRunId = (string) str()->uuid();
    insertDurableRunRow($parentRunId);
    insertDurableChildRunRow($parentRunId, $childRunId, json_encode(['input' => undecryptableSealedValue()]));

    // childRuns() (consumed by reconcile/cancel, which read only status) must not throw on an
    // undecryptable child context payload — only childRunForChild (the per-row resume read) is strict.
    $children = $store->childRuns($parentRunId);
    expect($children)->toHaveCount(1)
        ->and($children[0]['child_run_id'])->toBe($childRunId)
        ->and($children[0]['context_payload']['input'])->toBeNull();

    expect(fn () => $store->childRunForChild($childRunId))->toThrow(SwarmException::class, 'verify APP_KEY');
});

test('durable strict reads do not throw when encrypt at rest is disabled (#212 F6)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', false);
    app()->forgetInstance(SwarmPersistenceCipher::class);
    $store = app(DatabaseDurableRunStore::class);
    $runId = (string) str()->uuid();
    insertDurableRunRow($runId);

    // Encryption disabled → values stored as plaintext; openStrict passes them through.
    insertDurableBranchRow($runId, 'parallel:researcher', 'plain-input', 'plain-output');
    DB::table('swarm_durable_node_outputs')->insert([
        'run_id' => $runId, 'node_id' => 'researcher', 'output' => 'plain-node',
        'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $branch = $store->findBranch($runId, 'parallel:researcher');
    expect($branch['input'])->toBe('plain-input')
        ->and($branch['output'])->toBe('plain-output')
        ->and($store->hierarchicalNodeOutputsFor($runId, ['researcher'])['researcher'])->toBe('plain-node');
});

test('durable strict read tolerates an empty/NULL branch input without throwing (#212 F7)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $store = freshDurableRunStore();
    $runId = (string) str()->uuid();
    insertDurableRunRow($runId);

    // NULL input column → (string) null === '' → openStrict('') returns '' (no throw).
    DB::table('swarm_durable_branches')->insert([
        'run_id' => $runId, 'branch_id' => 'parallel:researcher', 'step_index' => 0,
        'node_id' => null, 'agent_class' => FakeResearcher::class, 'parent_node_id' => 'parallel',
        'status' => 'completed', 'input' => '', 'output' => null,
        'usage' => json_encode([]), 'metadata' => json_encode([]), 'failure' => null,
        'duration_ms' => 1, 'execution_token' => null, 'lease_acquired_at' => null,
        'leased_until' => null, 'attempts' => 0, 'queue_connection' => null, 'queue_name' => null,
        'started_at' => null, 'finished_at' => now(), 'expires_at' => now()->addHour(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $branch = $store->findBranch($runId, 'parallel:researcher');
    expect($branch['input'])->toBe('')
        ->and($branch['output'])->toBeNull();
});

test('per store database override seals context input when global driver is cache', function () {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.context.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(SwarmPersistenceCipher::class);

    $store = app(ContextStore::class);
    $runId = (string) str()->uuid();

    insertMinimalHistoryRow($runId, 'running');

    $store->put(RunContext::from('override-classified-prompt', $runId), 3600);

    $raw = DB::table('swarm_contexts')->where('run_id', $runId)->value('input');
    expect($raw)->toStartWith('sw0:');

    expect($store->find($runId)['input'])->toBe('override-classified-prompt');
});

test('database run history seals completed context input when encrypt at rest is enabled', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(DatabaseRunHistoryStore::class);

    $store = app(DatabaseRunHistoryStore::class);
    $runId = (string) str()->uuid();
    $context = RunContext::from('classified-history-prompt', $runId);

    $store->start($runId, FakeSequentialSwarm::class, 'sequential', $context, [], 3600);
    $store->complete(
        $runId,
        new SwarmResponse(
            output: 'final output',
            steps: [],
            metadata: ['run_id' => $runId],
            context: $context,
        ),
        3600,
    );

    $rawContext = DB::table('swarm_run_histories')->where('run_id', $runId)->value('context');
    expect(json_decode((string) $rawContext, true)['input'])->toStartWith('sw0:');

    expect($store->find($runId)['context']['input'])->toBe('classified-history-prompt');
});
