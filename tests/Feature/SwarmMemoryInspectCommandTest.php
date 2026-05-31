<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryInspected;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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
// Read-path coverage against the persisted snapshot table.
//
// The inspector treats every runner identically because all four runners
// (sequential, parallel, hierarchical, durable branch) write to the same
// `swarm_memory_snapshots` table — the proof that they share that table
// shape lives in `tests/Feature/Memory/RunnerSnapshotIntegrationTest`. The
// tests in this section therefore only need to exercise the inspector's
// read path against representative row shapes; per-runner naming would be
// misleading because nothing in the inspector branches on runner identity.
// ---------------------------------------------------------------------------

test('lists multiple steps in step_index order', function () {
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

test('lists multiple steps sharing a run id', function () {
    // The list view exposes every snapshot row recorded under `run_id`,
    // regardless of which runner wrote them — the only column the inspector
    // sorts by is `step_index`.
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

test('expands a single step from a multi-step run', function () {
    seedMemorySnapshotRow('run-hier', 0, runEntries('run-hier'));
    seedMemorySnapshotRow('run-hier', 1, runEntries('run-hier'));

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

test('expands a step including its recorded tool calls', function () {
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

test('--step exposes the persisted row recorded_at timestamp', function () {
    // The snapshot row carries created_at/updated_at; the inspector must
    // surface them rather than always reporting null. Seed a fixed instant so
    // the rendered ISO-8601 value is deterministic.
    $recordedAt = Carbon::parse('2026-05-27T12:00:00+00:00');

    seedMemorySnapshotRow('run-ts', 0, runEntries('run-ts', 1), createdAt: $recordedAt);

    Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-ts',
        '--step' => 0,
        '--format' => 'json',
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['recorded_at'])->toBe('2026-05-27T12:00:00+00:00');
    expect($output['updated_at'])->toBe('2026-05-27T12:00:00+00:00');
});

test('the list view exposes the persisted row recorded_at per step', function () {
    $recordedAt = Carbon::parse('2026-05-27T12:00:00+00:00');

    seedMemorySnapshotRow('run-ts-list', 0, runEntries('run-ts-list', 1), createdAt: $recordedAt);

    Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-ts-list',
        '--format' => 'json',
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['snapshots'][0]['recorded_at'])->toBe('2026-05-27T12:00:00+00:00');
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

// ---------------------------------------------------------------------------
// Cache-driver diagnostic — the SnapshotsMemory binding resolves to
// `NullSnapshotsMemory` and the command surfaces a configuration hint rather
// than the misleading "no snapshots found" message.
// ---------------------------------------------------------------------------

test('cache persistence driver fails with a configuration hint', function () {
    // Re-bind to the cache-driver snapshot store. The service provider
    // resolves this lazily on driver lookup, so it is enough to set the
    // config and forget the singleton.
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(SnapshotsMemory::class);

    $exit = Artisan::call('swarm:memory:inspect', ['run_id' => 'r-cache']);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('Ensure swarm.persistence.driver=database');
});

// ---------------------------------------------------------------------------
// Audit / event coverage — successful reads dispatch the `MemoryInspected`
// event and emit the `command.memory.inspect` audit category. Failed reads
// do not.
// ---------------------------------------------------------------------------

test('dispatches MemoryInspected on successful read', function () {
    seedMemorySnapshotRow('run-evt', 0, runEntries('run-evt'));
    seedMemorySnapshotRow('run-evt', 1, runEntries('run-evt'));

    Event::fake([MemoryInspected::class]);

    Artisan::call('swarm:memory:inspect', ['run_id' => 'run-evt', '--format' => 'json']);

    Event::assertDispatched(MemoryInspected::class, function (MemoryInspected $event): bool {
        return $event->runId === 'run-evt'
            && $event->stepIndex === null
            && $event->scopeFilter === null
            && $event->format === 'json'
            && $event->snapshotCount === 2;
    });
});

test('does not dispatch MemoryInspected when the run has no snapshots', function () {
    Event::fake([MemoryInspected::class]);

    $exit = Artisan::call('swarm:memory:inspect', ['run_id' => 'never-existed']);

    expect($exit)->toBe(1);

    Event::assertNotDispatched(MemoryInspected::class);
});

test('dispatches MemoryInspected carrying step and scope filters', function () {
    seedMemorySnapshotRow('run-fil', 0, mixedScopeEntries('run-fil'));

    Event::fake([MemoryInspected::class]);

    Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-fil',
        '--step' => 0,
        '--scope' => 'agent',
        '--format' => 'json',
    ]);

    Event::assertDispatched(MemoryInspected::class, function (MemoryInspected $event): bool {
        return $event->stepIndex === 0
            && $event->scopeFilter?->value === 'agent'
            && $event->snapshotCount === 1;
    });
});

// ---------------------------------------------------------------------------
// Truncation hint — tabular output points the operator at `--format=json` if
// any rendered cell hits the truncation budget.
// ---------------------------------------------------------------------------

test('table mode hints at --format=json when a tool-call cell truncates', function () {
    seedMemorySnapshotRow(
        'run-trunc',
        0,
        runEntries('run-trunc', 1),
        [[
            'id' => 'call-long',
            'name' => 'Recall',
            'arguments' => ['key' => str_repeat('x', 200)],
            'result' => 'ok',
        ]],
    );

    Artisan::call('swarm:memory:inspect', [
        'run_id' => 'run-trunc',
        '--step' => 0,
    ]);

    $output = Artisan::output();
    expect($output)->toContain('--format=json');
    expect($output)->toContain('truncated');
});
