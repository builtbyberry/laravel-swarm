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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalLoopSwarm;
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

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(array_fill(0, 10, 'draft-out'));
    FakeEditor::fake(array_fill(0, 10, 'refine-out'));
});

test('a loop whose bound is lowered below the persisted count across redeploy exits cleanly', function () {
    // Start with max_iterations = 3 (the fixture's plan).
    $response = FakeStaticHierarchicalLoopSwarm::make()->dispatchDurable('clamp-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    // Step 0 (init) + step 1 (writer) + step 2 (editor iteration 1, rewinds).
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    // editor has run once; cursor is back at editor with loop_iterations = 1.
    expect($manager->find($runId)['route_cursor']['loop_iterations']['editor_node'])->toBe(1)
        ->and($manager->find($runId)['route_cursor']['current_node_id'])->toBe('editor_node');

    // Advance once more so loop_iterations[editor_node] = 2.
    (new AdvanceDurableSwarm($runId, 3))->handle($manager);

    expect($manager->find($runId)['route_cursor']['loop_iterations']['editor_node'])->toBe(2)
        ->and($manager->find($runId)['route_cursor']['current_node_id'])->toBe('editor_node');

    // Simulate a redeploy that LOWERS the loop bound from 3 to 1, by rewriting the
    // persisted route_plan. The freshly-read bound is now below the persisted count.
    // The plan lives as a JSON column on the durable run-state side table, not on
    // the main durable-runs row (which only carries route_cursor/route_start_node_id).
    $run = $manager->find($runId);
    $plan = $run['route_plan'];
    $plan['nodes']['editor_node']['loop']['max_iterations'] = 1;

    DB::table('swarm_durable_run_state')
        ->where('run_id', $runId)
        ->update(['route_plan' => json_encode($plan)]);

    $nextStep = (int) $manager->find($runId)['next_step_index'];

    // Re-advance: the clamp falls out of the `$iteration < newMax` guard. The next
    // editor execution computes iteration = persisted(2) + 1 = 3 > newMax(1), so it
    // does NOT rewind — it advances to the exit and the run finishes cleanly with no
    // crash and no extra iteration beyond the one already in flight.
    (new AdvanceDurableSwarm($runId, $nextStep))->handle($manager);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['status'])->toBe('completed');

    // No rewind happened on the clamped step: the cursor exited to finish rather
    // than looping back to editor_node again.
    $editorRuns = array_filter(
        $history['context']['metadata']['executed_node_ids'],
        static fn (string $id): bool => $id === 'editor_node',
    );

    // The editor ran 3 times total (iterations 1, 2, and the final clamped step).
    expect($editorRuns)->toHaveCount(3)
        ->and($history['context']['metadata']['executed_node_ids'])->toContain('finish');
});
