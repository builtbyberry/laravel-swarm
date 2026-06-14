<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeReviewer;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalParallelInLoopSwarm;
use Illuminate\Support\Facades\Artisan;

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

    FakeEditor::fake(array_fill(0, 20, 'gather-out'));
    FakeResearcher::fake(array_fill(0, 20, 'research-out'));
    FakeWriter::fake(array_fill(0, 20, 'write-out'));
    FakeReviewer::fake(array_fill(0, 20, 'review-out'));
});

/**
 * Drive a durable run with a parallel-in-loop plan to completion, resolving
 * branch fan-outs as they appear. Returns the join loop_iterations observed
 * after each step where the join advanced.
 *
 * @return array<int, int>
 */
function driveParallelLoopRun(string $runId, DurableSwarmManager $manager): array
{
    $joinIterations = [];
    $guard = 0;

    while (true) {
        if ($guard++ > 200) {
            throw new RuntimeException('Parallel-in-loop durable run did not converge.');
        }

        $run = $manager->find($runId);
        $status = $run['status'];

        if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            break;
        }

        if ($status === 'waiting') {
            $parentNodeId = $run['current_node_id'];
            $branches = app(DurableRunStore::class)->branchesFor($runId, $parentNodeId);

            foreach ($branches as $branch) {
                if (($branch['status'] ?? null) === 'pending' || ($branch['status'] ?? null) === null) {
                    (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
                }
            }

            continue;
        }

        $step = (int) $run['next_step_index'];
        (new AdvanceDurableSwarm($runId, $step))->handle($manager);

        $after = $manager->find($runId);
        $joinIterations[] = (int) ($after['route_cursor']['loop_iterations']['join'] ?? 0);
    }

    return $joinIterations;
}

test('durable parallel-in-loop re-runs the branch agents on every pass', function () {
    // Count REAL branch-agent invocations, not executed_node_ids bookkeeping.
    // executed_node_ids/parallel_groups are re-appended by the join arm even when
    // the agents do NOT re-run, so they cannot distinguish a genuine re-dispatch
    // from a stale-output re-join. A counting closure fake increments only when the
    // agent's gateway is actually invoked, so it is the load-bearing signal that the
    // fan-out really re-executed each pass.
    $researchRuns = 0;
    $writeRuns = 0;
    FakeResearcher::fake(function () use (&$researchRuns): string {
        $researchRuns++;

        return 'research-out';
    });
    FakeWriter::fake(function () use (&$writeRuns): string {
        $writeRuns++;

        return 'write-out';
    });

    $response = FakeStaticHierarchicalParallelInLoopSwarm::make()->dispatchDurable('durable-par-loop');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    $joinIterations = driveParallelLoopRun($runId, $manager);

    expect($manager->find($runId)['status'])->toBe('completed')
        // The decisive assertion: each branch agent ran once per pass × 3 passes.
        // Pre-fix the fan-out only dispatched on pass 1 and the loop re-joined the
        // stale pass-1 rows, so these were 1 (not 3) and the run wrong-resulted.
        ->and($researchRuns)->toBe(3)
        ->and($writeRuns)->toBe(3);

    // The join loop counter increments exactly once per pass as the cursor rewinds
    // across the parallel join: 1 then 2 (the third pass exits at the bound).
    expect($joinIterations)->toContain(1)
        ->and($joinIterations)->toContain(2)
        ->and(max($joinIterations))->toBe(2);

    $history = app(SwarmHistory::class)->find($runId);
    $executed = $history['context']['metadata']['executed_node_ids'];
    $count = fn (string $id): int => count(array_filter($executed, static fn (string $n): bool => $n === $id));

    expect($count('gather'))->toBe(3)
        ->and($count('fan_out'))->toBe(3)
        ->and($count('branch_research'))->toBe(3)
        ->and($count('branch_write'))->toBe(3)
        ->and($count('join'))->toBe(3)
        // The run rewound to the fan-out entry between passes (3 parallel groups).
        ->and($history['context']['metadata']['parallel_groups'])->toHaveCount(3);
});

test('durable parallel-in-loop persists a fresh branch row per pass', function () {
    // A second, store-level guard on the same defect: with loop-scoped branch
    // clearing, the branch rows for the fan-out are deleted on each back-edge and
    // recreated next pass, so at any waiting boundary exactly the two current-pass
    // rows exist (never stale completed rows from a prior pass blocking dispatch).
    $response = FakeStaticHierarchicalParallelInLoopSwarm::make()->dispatchDurable('durable-par-loop-rows');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);
    $store = app(DurableRunStore::class);

    $waitingDispatchedBranches = 0;
    $guard = 0;

    while (true) {
        if ($guard++ > 200) {
            throw new RuntimeException('Parallel-in-loop durable run did not converge.');
        }

        $run = $manager->find($runId);

        if (in_array($run['status'], ['completed', 'failed', 'cancelled'], true)) {
            break;
        }

        if ($run['status'] === 'waiting') {
            $branches = $store->branchesFor($runId, $run['current_node_id']);

            // Every waiting boundary must expose exactly the two branches for the
            // CURRENT pass — pending and never carrying a prior pass's completed row.
            expect($branches)->toHaveCount(2);

            foreach ($branches as $branch) {
                if (($branch['status'] ?? null) === 'pending' || ($branch['status'] ?? null) === null) {
                    $waitingDispatchedBranches++;
                    (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
                }
            }

            continue;
        }

        (new AdvanceDurableSwarm($runId, (int) $run['next_step_index']))->handle($manager);
    }

    // 2 branches × 3 passes = 6 real branch dispatches.
    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($waitingDispatchedBranches)->toBe(6);
});
