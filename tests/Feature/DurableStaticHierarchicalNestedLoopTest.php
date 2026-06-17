<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeReviewer;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalNestedLoopSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(DurableSwarmManager::class);

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeWriter::fake(array_fill(0, 40, 'writer-out'));
    FakeEditor::fake(array_fill(0, 40, 'editor-out'));
    FakeReviewer::fake(array_fill(0, 40, 'reviewer-out'));
    FakeResearcher::fake(array_fill(0, 40, 'research-out'));
});

/**
 * Drive a durable run to completion one step at a time, returning the ordered
 * list of inner-loop counter values observed for $watchNode immediately after
 * each step. Captures the reset-on-outer-back-edge transition.
 *
 * @return array<int, int>
 */
function driveNestedDurableRun(string $runId, DurableSwarmManager $manager, string $watchNode): array
{
    $observed = [];
    $step = 0;

    while (true) {
        (new AdvanceDurableSwarm($runId, $step))->handle($manager);

        $run = $manager->find($runId);
        $observed[] = (int) ($run['route_cursor']['loop_iterations'][$watchNode] ?? 0);

        if (in_array($run['status'], ['completed', 'failed', 'cancelled'], true)) {
            break;
        }

        $step = (int) $run['next_step_index'];

        if ($step > 200) {
            throw new RuntimeException('Nested durable run did not converge.');
        }
    }

    return $observed;
}

test('durable nested loop resets the inner counter on every outer back-edge', function () {
    $response = FakeStaticHierarchicalNestedLoopSwarm::make()->dispatchDurable('durable-nested-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    $innerCounters = driveNestedDurableRun($runId, $manager, 'inner_loop');

    // The inner counter climbs 1->2->3 on the first outer pass, then RESETS back
    // to 1 after the outer back-edge fires, and climbs again. If the reset were
    // missing it would saturate at 3 and the inner loop would never re-run.
    expect($innerCounters)->toContain(1, 2, 3);

    // After it reaches 3 it must drop back to a lower value (the reset) before
    // climbing again — proving the inner loop re-ran on the second outer pass.
    $firstThree = array_search(3, $innerCounters, true);
    $tail = array_slice($innerCounters, $firstThree + 1);
    expect(min($tail))->toBeLessThan(3);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['context']['metadata']['executed_node_ids'])->toBe([
            'inner_body', 'inner_loop',
            'inner_body', 'inner_loop',
            'inner_body', 'inner_loop',
            'outer_loop',
            'inner_body', 'inner_loop',
            'inner_body', 'inner_loop',
            'inner_body', 'inner_loop',
            'outer_loop',
            'finish',
        ]);
});

test('durable nested loop final executed_node_ids equals the sync run', function () {
    // Sync ground truth.
    FakeWriter::fake(array_fill(0, 40, 'writer-out'));
    FakeEditor::fake(array_fill(0, 40, 'editor-out'));
    FakeReviewer::fake(array_fill(0, 40, 'reviewer-out'));
    $sync = FakeStaticHierarchicalNestedLoopSwarm::make()->run('sync-baseline');
    $syncExecuted = $sync->metadata['executed_node_ids'];

    // Durable.
    FakeWriter::fake(array_fill(0, 40, 'writer-out'));
    FakeEditor::fake(array_fill(0, 40, 'editor-out'));
    FakeReviewer::fake(array_fill(0, 40, 'reviewer-out'));
    $response = FakeStaticHierarchicalNestedLoopSwarm::make()->dispatchDurable('durable-equals-sync');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);
    driveNestedDurableRun($runId, $manager, 'inner_loop');

    $history = app(SwarmHistory::class)->find($runId);

    expect($history['context']['metadata']['executed_node_ids'])->toBe($syncExecuted);
});

test('durable nested loop survives a crash before checkpoint mid inner loop', function () {
    $response = FakeStaticHierarchicalNestedLoopSwarm::make()->dispatchDurable('durable-nested-crash');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    // Step 0 (init) and step 1 (inner_body) run cleanly.
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    expect($manager->find($runId)['route_cursor']['current_node_id'])->toBe('inner_loop')
        ->and($manager->find($runId)['next_step_index'])->toBe(2);

    // Crash during the first inner-loop iteration (step 2) before checkpoint.
    $manager->beforeStepCheckpointForTesting(function (): void {
        throw new RuntimeException('Simulated crash mid inner loop.');
    });

    expect(fn () => (new AdvanceDurableSwarm($runId, 2))->handle($manager))
        ->toThrow(RuntimeException::class, 'Simulated crash mid inner loop.');

    $manager->beforeStepCheckpointForTesting(null);

    // The inner iteration was not counted — the cursor never advanced past step 2.
    expect($manager->find($runId)['next_step_index'])->toBe(2)
        ->and($manager->find($runId)['route_cursor']['loop_iterations']['inner_loop'] ?? 0)->toBe(0);

    // Freeze the clock so the expired lease below and the runtime's live now()
    // resolve to the same instant — no real-clock margin to race against.
    $this->freezeTime();

    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update(['leased_until' => now()->subSeconds(5)]);

    // Resume and drive to completion — per-scope counts recompute exactly.
    $innerCounters = driveNestedDurableRun($runId, $manager, 'inner_loop');

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($innerCounters)->toContain(1, 2, 3)
        ->and($history['context']['metadata']['executed_node_ids'])->toBe([
            'inner_body', 'inner_loop',
            'inner_body', 'inner_loop',
            'inner_body', 'inner_loop',
            'outer_loop',
            'inner_body', 'inner_loop',
            'inner_body', 'inner_loop',
            'inner_body', 'inner_loop',
            'outer_loop',
            'finish',
        ]);
});
