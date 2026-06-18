<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Exercises the lean count-only list path on the database-backed history store
 * (#236). List views render only the per-run step COUNT, so the list query path
 * must NOT hydrate/decrypt every step — yet the count must still equal what full
 * hydration ({@see DatabaseRunHistoryStore::stepsForRecord()}) would yield, which
 * is the union of the legacy-JSON step indices and the normalized-row indices.
 */
beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(RunHistoryStore::class);

    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

/**
 * Insert a history row whose `steps` JSON column carries the given legacy step
 * entries and (optionally) normalized rows in swarm_run_steps.
 *
 * @param  array<int, array<string, mixed>>  $legacySteps  raw persisted-array step shapes (keyed by position; index lives in metadata.index when set)
 * @param  array<int, int>  $normalizedIndices  step_index values for normalized rows
 */
function seedLeanRun(string $runId, array $legacySteps, array $normalizedIndices): void
{
    $timestamp = Carbon::now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'App\\Swarms\\LeanSwarm',
        'topology' => 'sequential',
        'status' => 'completed',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode($legacySteps),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
        'expires_at' => null,
        'finished_at' => $timestamp,
    ]);

    foreach ($normalizedIndices as $stepIndex) {
        DB::table('swarm_run_steps')->insert([
            'run_id' => $runId,
            'step_index' => $stepIndex,
            'agent_class' => 'App\\Agents\\LeanAgent',
            'input' => 'in-'.$stepIndex,
            'output' => 'out-'.$stepIndex,
            'artifacts' => json_encode([]),
            'metadata' => json_encode(['index' => $stepIndex]),
            'expires_at' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}

/**
 * @param  array<int, int|null>  $indices  metadata.index for each legacy step; null means the key is absent (positional fallback)
 * @return array<int, array<string, mixed>>
 */
function legacyStepsWithIndices(array $indices): array
{
    return array_map(static function (?int $index): array {
        $step = [
            'agent_class' => 'App\\Agents\\LeanAgent',
            'input' => 'legacy-in',
            'output' => 'legacy-out',
            'artifacts' => [],
            'metadata' => [],
        ];

        if ($index !== null) {
            $step['metadata']['index'] = $index;
        }

        return $step;
    }, $indices);
}

test('lean step_count equals full hydration count across every union shape', function (): void {
    // legacy-JSON-only: COUNT(*) on swarm_run_steps would return 0 here.
    seedLeanRun('run-legacy-only', legacyStepsWithIndices([0, 1, 2]), []);

    // normalized-only.
    seedLeanRun('run-normalized-only', [], [0, 1]);

    // overlapping indices: legacy {0,1,2} ∪ normalized {2,3} = {0,1,2,3} → 4.
    seedLeanRun('run-overlap', legacyStepsWithIndices([0, 1, 2]), [2, 3]);

    // disjoint indices: legacy {0,1} ∪ normalized {5,6} → 4.
    seedLeanRun('run-disjoint', legacyStepsWithIndices([0, 1]), [5, 6]);

    // legacy with missing metadata.index → positional fallback (0,1,2) → 3.
    seedLeanRun('run-positional', legacyStepsWithIndices([null, null, null]), []);

    /** @var DatabaseRunHistoryStore $store */
    $store = app(RunHistoryStore::class);

    $expected = [
        'run-legacy-only' => 3,
        'run-normalized-only' => 2,
        'run-overlap' => 4,
        'run-disjoint' => 4,
        'run-positional' => 3,
    ];

    // Lean list path step_count must equal the count of full stepsForRecord().
    $lean = collect($store->query(limit: 25))->keyBy('run_id');

    foreach ($expected as $runId => $count) {
        $full = $store->find($runId);

        expect($full)->not->toBeNull()
            ->and(count($full['steps']))->toBe($count, "full hydration count for {$runId}")
            ->and($lean[$runId]['step_count'])->toBe($count, "lean step_count for {$runId}");

        // Lean rows never carry decrypted step payloads.
        expect($lean[$runId])->not->toHaveKey('steps');
    }
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'database-backed history store');

test('swarm:history and swarm:status render the union step count for list views', function (): void {
    seedLeanRun('run-legacy-only', legacyStepsWithIndices([0, 1, 2]), []);
    seedLeanRun('run-overlap', legacyStepsWithIndices([0, 1, 2]), [2, 3]);

    Artisan::call('swarm:history');
    $historyOutput = Artisan::output();

    Artisan::call('swarm:status');
    $statusOutput = Artisan::output();

    foreach ([$historyOutput, $statusOutput] as $output) {
        expect($output)->toContain('run-legacy-only')
            ->and($output)->toContain('run-overlap');
    }

    // Each list row renders the correct union count: legacy-only → 3, overlap → 4.
    expect(stepCountCell($historyOutput, 'run-legacy-only'))->toBe('3')
        ->and(stepCountCell($historyOutput, 'run-overlap'))->toBe('4')
        ->and(stepCountCell($statusOutput, 'run-legacy-only'))->toBe('3')
        ->and(stepCountCell($statusOutput, 'run-overlap'))->toBe('4');
});

test('the list path does not scale queries with the number of runs', function (): void {
    foreach (range(1, 12) as $n) {
        seedLeanRun('run-'.$n, legacyStepsWithIndices([0, 1]), [2, 3]);
    }

    /** @var DatabaseRunHistoryStore $store */
    $store = app(RunHistoryStore::class);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $runs = $store->query(limit: 25);

    $queries = collect(DB::getQueryLog())
        ->reject(fn (array $entry): bool => str_contains(strtolower($entry['query']), 'sqlite_master'));

    DB::disableQueryLog();

    expect($runs)->toHaveCount(12)
        // records query + one batched (run_id, step_index) query, independent of run count.
        ->and($queries)->toHaveCount(2);

    foreach ($runs as $run) {
        expect($run['step_count'])->toBe(4);
    }
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'database-backed history store');

/**
 * Pull the Steps column value for a given run id out of rendered table output.
 */
function stepCountCell(string $output, string $runId): string
{
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (! str_contains($line, $runId)) {
            continue;
        }

        $cells = array_values(array_filter(
            array_map('trim', explode('|', $line)),
            static fn (string $cell): bool => $cell !== '',
        ));

        // Columns: Run ID | Swarm | Topology | Status | Phase | Steps | Started/Duration
        return $cells[5] ?? '';
    }

    return '';
}
