<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ConversationRunResolver;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryDumped;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    // Dump reads route through the database-backed MemoryStore + snapshot
    // recorder; seed rows directly against the configured tables.
    config()->set('swarm.persistence.driver', 'database');
});

function dumpSeedRunHistory(string $runId): void
{
    if (DB::table('swarm_run_histories')->where('run_id', $runId)->exists()) {
        return;
    }

    $now = Carbon::now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'completed',
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

function dumpSeedMemory(MemoryScope $scope, string $scopeId, string $key, mixed $value = 'v'): void
{
    if ($scope === MemoryScope::Run) {
        dumpSeedRunHistory($scopeId);
    }

    $now = Carbon::now('UTC');

    DB::table('swarm_memories')->insert([
        'scope' => $scope->value,
        'scope_id' => $scopeId,
        'run_id' => $scope === MemoryScope::Run ? $scopeId : null,
        'key' => $key,
        'value' => json_encode($value, JSON_THROW_ON_ERROR),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 * @param  array<int, array<string, mixed>>  $toolCalls
 */
function dumpSeedSnapshot(string $runId, int $stepIndex, array $entries = [], array $toolCalls = []): void
{
    dumpSeedRunHistory($runId);

    $now = Carbon::now('UTC');

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

// ---------------------------------------------------------------------------
// Run subject — JSON
// ---------------------------------------------------------------------------

test('exports a run as a json envelope with entries and snapshot references', function (): void {
    dumpSeedMemory(MemoryScope::Run, 'run-1', 'goal', 'ship it');
    dumpSeedMemory(MemoryScope::Run, 'run-1', 'status', 'green');
    dumpSeedSnapshot('run-1', 0, [['scope' => 'run', 'scope_id' => 'run-1', 'key' => 'goal', 'value' => 'ship it', 'metadata' => [], 'created_at' => null, 'updated_at' => null]], [['name' => 't', 'arguments' => [], 'result' => 'ok']]);

    $exit = Artisan::call('swarm:memory:dump', ['id' => 'run-1', '--format' => 'json']);
    $out = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($out['ok'])->toBeTrue()
        ->and($out['schema_version'])->toBe('1.0')
        ->and($out['subject_type'])->toBe('run')
        ->and($out['subject_id'])->toBe('run-1')
        ->and($out['entry_count'])->toBe(2)
        ->and($out['snapshot_count'])->toBe(1)
        ->and($out['entries'])->toHaveCount(2)
        ->and($out['snapshots'][0])->toHaveKeys(['run_id', 'step_index', 'entry_count', 'tool_call_count'])
        // references-only by default: no full payload
        ->and($out['snapshots'][0])->not->toHaveKey('entries')
        ->and($out['snapshots'][0])->not->toHaveKey('tool_calls')
        ->and($out['snapshots'][0]['tool_call_count'])->toBe(1);
});

test('--include-snapshots embeds full snapshot payloads', function (): void {
    dumpSeedSnapshot('run-1', 0, [['scope' => 'run', 'scope_id' => 'run-1', 'key' => 'goal', 'value' => 'x', 'metadata' => [], 'created_at' => null, 'updated_at' => null]], [['name' => 't', 'arguments' => ['a' => 1], 'result' => 'ok']]);

    $exit = Artisan::call('swarm:memory:dump', ['id' => 'run-1', '--format' => 'json', '--include-snapshots' => true]);
    $out = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($out['include_snapshots'])->toBeTrue()
        ->and($out['snapshots'][0])->toHaveKey('entries')
        ->and($out['snapshots'][0])->toHaveKey('tool_calls')
        ->and($out['snapshots'][0]['entries'])->toHaveCount(1)
        ->and($out['snapshots'][0]['tool_calls'])->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// NDJSON
// ---------------------------------------------------------------------------

test('ndjson format emits a header record then entry and snapshot records', function (): void {
    dumpSeedMemory(MemoryScope::Run, 'run-1', 'goal', 'ship it');
    dumpSeedSnapshot('run-1', 0, [], []);

    $exit = Artisan::call('swarm:memory:dump', ['id' => 'run-1', '--format' => 'ndjson']);
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output())), static fn (string $l): bool => $l !== ''));

    $records = array_map(static fn (string $l): array => json_decode($l, true), $lines);

    expect($exit)->toBe(0)
        ->and($records[0]['record'])->toBe('header')
        ->and($records[0]['subject_type'])->toBe('run')
        ->and($records[0]['entry_count'])->toBe(1)
        ->and(array_column($records, 'record'))->toBe(['header', 'entry', 'snapshot']);
});

// ---------------------------------------------------------------------------
// Conversation subject
// ---------------------------------------------------------------------------

test('detects a conversation id and exports conversation-scoped entries without run expansion', function (): void {
    dumpSeedMemory(MemoryScope::Conversation, 'conv-1', 'thread-summary', 'hello');

    $exit = Artisan::call('swarm:memory:dump', ['id' => 'conv-1', '--format' => 'json']);
    $out = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($out['subject_type'])->toBe('conversation')
        ->and($out['entry_count'])->toBe(1)
        ->and($out['runs_expanded'])->toBeFalse()
        ->and($out['skipped_runs'])->toBe([])
        ->and($out['conversation_run_resolver'])->toContain('NullConversationRunResolver');
});

test('a bound resolver expands a conversation into runs and reports skipped runs', function (): void {
    dumpSeedMemory(MemoryScope::Conversation, 'conv-1', 'thread-summary', 'hello');
    dumpSeedMemory(MemoryScope::Run, 'run-a', 'goal', 'do thing');
    dumpSeedSnapshot('run-a', 0, [], []);

    app()->instance(ConversationRunResolver::class, new class implements ConversationRunResolver
    {
        public function resolve(string $conversationId): array
        {
            return ['run-a', 'run-missing'];
        }
    });

    $exit = Artisan::call('swarm:memory:dump', ['id' => 'conv-1', '--format' => 'json', '--include-snapshots' => true]);
    $out = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($out['subject_type'])->toBe('conversation')
        ->and($out['runs_expanded'])->toBeTrue()
        // conversation entry + run-a's run-scoped entry
        ->and($out['entry_count'])->toBe(2)
        ->and($out['snapshot_count'])->toBe(1)
        ->and($out['skipped_runs'])->toBe(['run-missing']);
});

// ---------------------------------------------------------------------------
// Subject resolution: --as override and ambiguity
// ---------------------------------------------------------------------------

test('--as=run forces the run interpretation', function (): void {
    dumpSeedMemory(MemoryScope::Run, 'id-x', 'goal', 'g');

    $exit = Artisan::call('swarm:memory:dump', ['id' => 'id-x', '--as' => 'run', '--format' => 'json']);
    $out = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)->and($out['subject_type'])->toBe('run');
});

test('an id matching both a run and a conversation is refused without --as', function (): void {
    // same id is both a run history row and a conversation scope_id
    dumpSeedRunHistory('dup-id');
    dumpSeedMemory(MemoryScope::Conversation, 'dup-id', 'k', 'v');

    $exit = Artisan::call('swarm:memory:dump', ['id' => 'dup-id', '--format' => 'json']);
    $out = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)
        ->and($out['ok'])->toBeFalse()
        ->and($out['error'])->toContain('--as');

    // disambiguated, it succeeds either way
    $exit = Artisan::call('swarm:memory:dump', ['id' => 'dup-id', '--as' => 'conversation', '--format' => 'json']);
    expect($exit)->toBe(0)
        ->and(json_decode(Artisan::output(), true)['subject_type'])->toBe('conversation');
});

// ---------------------------------------------------------------------------
// File output
// ---------------------------------------------------------------------------

test('--output writes the export to a file and keeps stdout clean', function (): void {
    dumpSeedMemory(MemoryScope::Run, 'run-1', 'goal', 'g');

    $path = sys_get_temp_dir().'/swarm-dump-test-'.bin2hex(random_bytes(4)).'.json';

    try {
        $exit = Artisan::call('swarm:memory:dump', ['id' => 'run-1', '--output' => $path]);
        $stdout = Artisan::output();

        expect($exit)->toBe(0)
            ->and($stdout)->toContain('Wrote')
            ->and(file_exists($path))->toBeTrue();

        $written = json_decode((string) file_get_contents($path), true);
        expect($written['subject_type'])->toBe('run')->and($written['entry_count'])->toBe(1);
    } finally {
        @unlink($path);
    }
});

test('--output to a non-existent directory fails cleanly', function (): void {
    dumpSeedMemory(MemoryScope::Run, 'run-1', 'goal', 'g');

    $exit = Artisan::call('swarm:memory:dump', ['id' => 'run-1', '--output' => '/no/such/dir/dump.json']);
    $out = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)->and($out['ok'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Error paths
// ---------------------------------------------------------------------------

test('a missing id errors with ok:false and a non-zero exit', function (): void {
    $exit = Artisan::call('swarm:memory:dump', ['id' => 'nope', '--format' => 'json']);
    $out = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)
        ->and($out['ok'])->toBeFalse()
        ->and($out['error'])->toContain('nope');
});

test('an invalid --format errors', function (): void {
    $exit = Artisan::call('swarm:memory:dump', ['id' => 'run-1', '--format' => 'yaml']);

    expect($exit)->toBe(1);
});

test('the cache driver fails with a configuration hint instead of a partial export', function (): void {
    config()->set('swarm.persistence.driver', 'cache');

    $exit = Artisan::call('swarm:memory:dump', ['id' => 'run-1', '--format' => 'json']);
    $out = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)
        ->and($out['ok'])->toBeFalse()
        ->and($out['error'])->toContain('swarm.persistence.driver=database');
});

// ---------------------------------------------------------------------------
// Event / audit
// ---------------------------------------------------------------------------

test('dispatches MemoryDumped on a successful export', function (): void {
    dumpSeedMemory(MemoryScope::Run, 'run-1', 'goal', 'g');
    dumpSeedSnapshot('run-1', 0, [], []);

    Event::fake([MemoryDumped::class]);

    Artisan::call('swarm:memory:dump', ['id' => 'run-1', '--format' => 'json']);

    Event::assertDispatched(MemoryDumped::class, function (MemoryDumped $event): bool {
        return $event->subjectType === 'run'
            && $event->subjectId === 'run-1'
            && $event->entryCount === 1
            && $event->snapshotCount === 1
            && $event->runsExpanded === false;
    });
});

test('does not dispatch MemoryDumped for a missing id', function (): void {
    Event::fake([MemoryDumped::class]);

    Artisan::call('swarm:memory:dump', ['id' => 'nope', '--format' => 'json']);

    Event::assertNotDispatched(MemoryDumped::class);
});
