<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    // The snapshots table is part of the database persistence stack. The
    // command reads rows directly via the configured table name and works
    // regardless of which runner produced them — the four runners all funnel
    // their snapshots through `swarm_memory_snapshots`, so seeding rows here
    // is faithful to what a sequential, parallel, hierarchical, or durable
    // branch run would have written.
    config()->set('swarm.persistence.driver', 'database');
});

/**
 * @param  array<int, array<string, mixed>>  $entries
 * @param  array<int, array<string, mixed>>  $toolCalls
 */
function seedMemorySnapshotRow(
    string $runId,
    int $stepIndex,
    array $entries = [],
    array $toolCalls = [],
    ?Carbon $createdAt = null,
): void {
    $now = $createdAt ?? Carbon::now('UTC');

    // The snapshots table has a FK to swarm_run_histories.run_id; seed the
    // parent row first if it does not exist yet.
    $historyExists = DB::table('swarm_run_histories')->where('run_id', $runId)->exists();

    if (! $historyExists) {
        DB::table('swarm_run_histories')->insert([
            'run_id' => $runId,
            'swarm_class' => 'ExampleSwarm',
            'topology' => 'sequential',
            'status' => 'running',
            'context' => json_encode([]),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'output' => null,
            'usage' => json_encode([]),
            'error' => null,
            'artifacts' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    DB::table('swarm_memory_snapshots')->insert([
        'run_id' => $runId,
        'step_index' => $stepIndex,
        'payload' => json_encode([
            'run_id' => $runId,
            'step_index' => $stepIndex,
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR),
        'tool_calls' => json_encode($toolCalls, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function runEntries(string $runId, int $count = 2): array
{
    $entries = [];
    for ($i = 0; $i < $count; $i++) {
        $entries[] = [
            'scope' => 'run',
            'scope_id' => $runId,
            'key' => "run-key-{$i}",
            'value' => "run-value-{$i}",
            'metadata' => [],
            'created_at' => '2026-05-27T12:00:00+00:00',
            'updated_at' => '2026-05-27T12:00:00+00:00',
        ];
    }

    return $entries;
}

function mixedScopeEntries(string $runId): array
{
    return [
        ['scope' => 'run', 'scope_id' => $runId, 'key' => 'run-key', 'value' => 'r', 'metadata' => [], 'created_at' => null, 'updated_at' => null],
        ['scope' => 'conversation', 'scope_id' => 'conv-1', 'key' => 'conv-key', 'value' => 'c', 'metadata' => [], 'created_at' => null, 'updated_at' => null],
        ['scope' => 'agent', 'scope_id' => 'AgentClass', 'key' => 'agent-key', 'value' => 'a', 'metadata' => [], 'created_at' => null, 'updated_at' => null],
        ['scope' => 'swarm', 'scope_id' => 'SwarmClass', 'key' => 'swarm-key', 'value' => 's', 'metadata' => [], 'created_at' => null, 'updated_at' => null],
    ];
}

// ---------------------------------------------------------------------------
// Per-runner inspection — the inspector treats every runner identically because
// they all write to `swarm_memory_snapshots`. We simulate each runner's typical
// row shape and confirm the command surfaces it the same way.
// ---------------------------------------------------------------------------

test('inspects a sequential-runner-style run (one row per step in order)', function () {
    seedMemorySnapshotRow('run-seq', 0, runEntries('run-seq'));
    seedMemorySnapshotRow('run-seq', 1, runEntries('run-seq'));
    seedMemorySnapshotRow('run-seq', 2, runEntries('run-seq'));

    $exit = Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-seq',
        '--format' => 'json',
    ]);

    expect($exit)->toBe(0);

    $output = json_decode(Artisan::output(), true);
    expect($output['ok'])->toBeTrue();
    expect($output['snapshot_count'])->toBe(3);
    expect(array_column($output['snapshots'], 'step_index'))->toBe([0, 1, 2]);
});

test('inspects a parallel-runner-style run (multiple branch steps sharing a run_id)', function () {
    // Parallel runners snapshot each branch under the same run_id with
    // monotonically increasing step indices.
    seedMemorySnapshotRow('run-par', 0, runEntries('run-par'));
    seedMemorySnapshotRow('run-par', 1, runEntries('run-par'));
    seedMemorySnapshotRow('run-par', 2, runEntries('run-par'));

    $exit = Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-par',
        '--format' => 'json',
    ]);

    expect($exit)->toBe(0);
    $output = json_decode(Artisan::output(), true);
    expect($output['snapshot_count'])->toBe(3);
});

test('inspects a hierarchical-runner-style run (coordinator at step 0, workers after)', function () {
    seedMemorySnapshotRow('run-hier', 0, runEntries('run-hier')); // coordinator
    seedMemorySnapshotRow('run-hier', 1, runEntries('run-hier')); // worker

    $exit = Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-hier',
        '--step' => 1,
        '--format' => 'json',
    ]);

    expect($exit)->toBe(0);
    $output = json_decode(Artisan::output(), true);
    expect($output['ok'])->toBeTrue();
    expect($output['step_index'])->toBe(1);
    expect($output['entry_count'])->toBe(2);
});

test('inspects a durable-branch-style run with recorded tool calls', function () {
    seedMemorySnapshotRow('run-dur', 0, runEntries('run-dur'), [
        ['id' => 'call-1', 'name' => 'Recall', 'arguments' => ['key' => 'pref'], 'result' => 'dark', 'result_id' => 'r-1'],
        ['id' => 'call-2', 'name' => 'Recall', 'arguments' => ['key' => 'lang'], 'result' => 'en', 'result_id' => 'r-2'],
    ]);

    $exit = Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-dur',
        '--step' => 0,
        '--format' => 'json',
    ]);

    expect($exit)->toBe(0);
    $output = json_decode(Artisan::output(), true);
    expect($output['tool_call_count'])->toBe(2);
    expect($output['tool_calls'][0]['name'])->toBe('Recall');
    expect($output['tool_calls'][1]['arguments'])->toBe(['key' => 'lang']);
});

// ---------------------------------------------------------------------------
// Default behaviour — no --step lists all steps for a run.
// ---------------------------------------------------------------------------

test('default behaviour lists every step recorded for the run as a summary table', function () {
    seedMemorySnapshotRow('run-list', 0, runEntries('run-list', 1));
    seedMemorySnapshotRow('run-list', 1, runEntries('run-list', 3));

    $exit = Artisan::call('swarm:memory:inspect', ['run_id' => 'run-list']);

    expect($exit)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('Memory snapshots for run [run-list]');
    expect($output)->toContain('Step');
    expect($output)->toContain('Entries');
    expect($output)->toContain('Tool Calls');
});

// ---------------------------------------------------------------------------
// Format options
// ---------------------------------------------------------------------------

test('--format=table renders a human-readable summary by default', function () {
    seedMemorySnapshotRow('run-fmt', 0, runEntries('run-fmt'));

    Artisan::call('swarm:memory:inspect', ['run_id' => 'run-fmt']);

    $output = Artisan::output();
    expect($output)->toContain('Memory snapshots for run [run-fmt]');
});

test('--format=table --step=N expands entries and tool calls into two tables', function () {
    seedMemorySnapshotRow(
        'run-detail',
        0,
        runEntries('run-detail', 1),
        [['name' => 'Recall', 'arguments' => ['key' => 'pref'], 'result' => 'dark']],
    );

    Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-detail',
        '--step' => 0,
    ]);

    $output = Artisan::output();
    expect($output)->toContain('Memory snapshot for run [run-detail] step [0]');
    expect($output)->toContain('Entries');
    expect($output)->toContain('Tool calls');
    expect($output)->toContain('Recall');
});

test('--format=json emits a structured envelope with entries and tool calls', function () {
    seedMemorySnapshotRow(
        'run-json',
        0,
        runEntries('run-json', 1),
        [['name' => 'Recall', 'arguments' => [], 'result' => 'ok']],
    );

    Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-json',
        '--step' => 0,
        '--format' => 'json',
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['ok'])->toBeTrue();
    expect($output['run_id'])->toBe('run-json');
    expect($output['step_index'])->toBe(0);
    expect($output['entries'])->toHaveCount(1);
    expect($output['tool_calls'])->toHaveCount(1);
});

test('invalid --format fails fast with a clear error', function () {
    $exit = Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-fmt',
        '--format' => 'yaml',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('--format must be one of: table, json.');
});

// ---------------------------------------------------------------------------
// Scope filtering
// ---------------------------------------------------------------------------

test('--scope=run keeps only Run-scoped entries in the rendered view', function () {
    seedMemorySnapshotRow('run-scope', 0, mixedScopeEntries('run-scope'));

    Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-scope',
        '--step' => 0,
        '--scope' => 'run',
        '--format' => 'json',
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['entries'])->toHaveCount(1);
    expect($output['entries'][0]['scope'])->toBe('run');
    expect($output['scope_filter'])->toBe('run');
});

test('--scope=conversation keeps only Conversation-scoped entries', function () {
    seedMemorySnapshotRow('run-scope', 0, mixedScopeEntries('run-scope'));

    Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-scope',
        '--step' => 0,
        '--scope' => 'conversation',
        '--format' => 'json',
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['entries'])->toHaveCount(1);
    expect($output['entries'][0]['scope'])->toBe('conversation');
});

test('--scope=agent keeps only Agent-scoped entries', function () {
    seedMemorySnapshotRow('run-scope', 0, mixedScopeEntries('run-scope'));

    Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-scope',
        '--step' => 0,
        '--scope' => 'agent',
        '--format' => 'json',
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['entries'])->toHaveCount(1);
    expect($output['entries'][0]['scope'])->toBe('agent');
});

test('--scope=swarm keeps only Swarm-scoped entries', function () {
    seedMemorySnapshotRow('run-scope', 0, mixedScopeEntries('run-scope'));

    Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-scope',
        '--step' => 0,
        '--scope' => 'swarm',
        '--format' => 'json',
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['entries'])->toHaveCount(1);
    expect($output['entries'][0]['scope'])->toBe('swarm');
});

test('omitting --scope returns every scope present in the snapshot', function () {
    seedMemorySnapshotRow('run-scope', 0, mixedScopeEntries('run-scope'));

    Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-scope',
        '--step' => 0,
        '--format' => 'json',
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['entries'])->toHaveCount(4);
    expect($output['scope_filter'])->toBeNull();
});

test('invalid --scope fails fast with a clear error', function () {
    $exit = Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-scope',
        '--scope' => 'galaxy',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('--scope must be one of: run, conversation, agent, swarm.');
});

// ---------------------------------------------------------------------------
// Error paths
// ---------------------------------------------------------------------------

test('missing run id surfaces a clear error and exits non-zero', function () {
    $exit = Artisan::call('swarm:memory:inspect', ['run_id' => 'never-existed']);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('No snapshots found for run_id=never-existed.');
});

test('missing run id with --format=json emits an error envelope', function () {
    $exit = Artisan::call('swarm:memory:inspect', [
        'run_id' => 'never-existed',
        '--format' => 'json',
    ]);

    expect($exit)->toBe(1);
    $output = json_decode(Artisan::output(), true);
    expect($output['ok'])->toBeFalse();
    expect($output['error'])->toContain('No snapshots found');
});

test('missing step index for an otherwise-known run surfaces a precise error', function () {
    seedMemorySnapshotRow('run-known', 0, runEntries('run-known'));

    $exit = Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-known',
        '--step' => 99,
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('No snapshot found for run_id=run-known step=99.');
});

test('invalid --step fails fast', function () {
    $exit = Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-known',
        '--step' => 'abc',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('--step must be a non-negative integer.');
});

test('empty run id argument fails fast', function () {
    $exit = Artisan::call('swarm:memory:inspect', ['run_id' => '   ']);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('run_id argument is required.');
});
