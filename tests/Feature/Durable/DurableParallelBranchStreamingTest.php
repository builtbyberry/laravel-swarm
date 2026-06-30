<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Streaming\View\VoidedEvent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\HierarchicalFanOutStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelDurableStreamingSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Mirror the durable runtime setup used by the sequential per-node streaming test. */
function configureDurableParallelBranchStreamingRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([
        ContextStore::class,
        ArtifactRepository::class,
        RunHistoryStore::class,
        DurableRunStore::class,
        SwarmRunner::class,
        DurableSwarmManager::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

/** node_id stamped on a branch event's payload, or null for a barrier / top-level event. */
function parallelBranchNodeId(object $event): ?string
{
    $payload = ($event instanceof VoidedEvent ? $event->event : $event)->toArray();

    return is_string($payload['node_id'] ?? null) ? $payload['node_id'] : null;
}

function parallelBranchEventCount(string $runId, ?string $nodeId = null, ?int $epoch = null): int
{
    $query = DB::table('swarm_stream_events')->where('run_id', $runId);

    if ($nodeId !== null) {
        $query->where('node_id', $nodeId);
    }

    if ($epoch !== null) {
        $query->where('attempt_epoch', $epoch);
    }

    return $query->count();
}

beforeEach(function () {
    configureDurableParallelBranchStreamingRuntime();
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    FlakyStreamEditor::reset(failuresBeforeSuccess: 1);
});

test('an attributed parallel durable run streams real per-branch rows stamped with node_id = branch_id and attempt_epoch = branch attempts (#312 forcing function)', function () {
    FlakyStreamEditor::reset(failuresBeforeSuccess: 0); // both branches commit cleanly

    $runId = ParallelDurableStreamingSwarm::make()->dispatchDurable('branch-task')->runId;
    $manager = app(DurableSwarmManager::class);

    // Pinned at run-start from the swarm's own #[DurableStreaming].
    expect((bool) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('durable_streaming'))->toBeTrue();

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);            // fan out branches
    (new AdvanceDurableBranch($runId, 'parallel:0'))->handle($manager); // flaky branch (clean this run)
    (new AdvanceDurableBranch($runId, 'parallel:1'))->handle($manager); // sibling branch

    // Each branch streamed into the run-scoped log under its OWN node id (the
    // branch_id fallback, since top-level parallel branches persist node_id = null)
    // and the branch's first-attempt epoch (attempts = 1). Branches never collapse
    // into one bucket (gate H3).
    expect(parallelBranchEventCount($runId, 'parallel:0', 1))->toBeGreaterThan(0)
        ->and(parallelBranchEventCount($runId, 'parallel:1', 1))->toBeGreaterThan(0)
        ->and(parallelBranchEventCount($runId, 'parallel:0', 0))->toBe(0)
        ->and(parallelBranchEventCount($runId, 'parallel:1', 0))->toBe(0);

    // The join completes the run; no per-branch seal was emitted at branch commit.
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);
    expect($manager->find($runId)['status'])->toBe('completed');
});

test('one branch crashes + resumes while the sibling commits: only the crashed branch is voided, no SealedCausalWindowException (#312 acceptance)', function () {
    $runId = ParallelDurableStreamingSwarm::make()->dispatchDurable('branch-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager); // fan out branches

    // Branch parallel:0 streams a partial delta, then crashes mid-node; a retry is
    // scheduled. Its one partial event is in the log under epoch 1, not yet voided.
    (new AdvanceDurableBranch($runId, 'parallel:0'))->handle($manager);
    expect(parallelBranchEventCount($runId, 'parallel:0', 1))->toBe(1)
        ->and(FlakyStreamEditor::$attempts)->toBe(1);

    // Sibling branch parallel:1 commits cleanly WHILE parallel:0 is mid-retry. The
    // sibling's commit must NOT seal the in-flight crashed branch's window — seal is
    // on-join, never per-branch-commit (gate H2). This advance must not throw.
    (new AdvanceDurableBranch($runId, 'parallel:1'))->handle($manager);
    expect(parallelBranchEventCount($runId, 'parallel:1', 1))->toBeGreaterThan(0)
        // No void edge exists yet — the crashed branch has not re-executed.
        ->and(
            DB::table('swarm_stream_events')->where('run_id', $runId)
                ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)->count()
        )->toBe(0);

    // Recover past the backoff and re-execute the crashed branch. Its attempts bump
    // to 2 on lease re-acquire, so the resumed attempt streams under epoch 2 and
    // retracts the crashed epoch-1 attempt — keyed on (parallel:0, epoch), so the
    // committed sibling parallel:1 is never touched.
    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');
    (new AdvanceDurableBranch($runId, 'parallel:0'))->handle($manager);
    expect(FlakyStreamEditor::$attempts)->toBe(2)
        ->and(parallelBranchEventCount($runId, 'parallel:0', 2))->toBeGreaterThan(0);

    // The join completes the run.
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);
    expect($manager->find($runId)['status'])->toBe('completed');

    // Exactly one void edge was written, and it targets the crashed parallel:0
    // attempt's first event (its target uuid resolves to a parallel:0 row). The
    // edge row itself carries no node_id — it is a top-level retraction addressed
    // by void_target_event_uuid.
    $voidRows = DB::table('swarm_stream_events')->where('run_id', $runId)
        ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)->get();
    expect($voidRows)->toHaveCount(1);
    $targetNodeId = DB::table('swarm_stream_events')->where('run_id', $runId)
        ->where('event_uuid', $voidRows->first()->void_target_event_uuid)->value('node_id');
    expect($targetNodeId)->toBe('parallel:0');

    $log = app(CausalLogStore::class);

    // Clean fold: parallel:0 shows only the resumed attempt (epoch 2); the crashed
    // partial is suppressed. parallel:1 is untouched (still its single committed attempt).
    $cleanParallel0 = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Clean),
        fn ($event) => parallelBranchNodeId($event) === 'parallel:0',
    ));
    expect($cleanParallel0)->not->toBeEmpty();
    foreach ($cleanParallel0 as $event) {
        expect($event->attemptEpoch)->toBe(2);
    }

    // Everything fold: the crashed parallel:0 event survives, wrapped as voided; the
    // sibling parallel:1 is never voided.
    $voidedParallel1 = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Everything),
        fn ($event) => $event instanceof VoidedEvent && parallelBranchNodeId($event) === 'parallel:1',
    ));
    expect($voidedParallel1)->toBeEmpty();
});

test('a hierarchical fan-out streams each branch under its non-null node_id and seals on join, never at branch commit (#312 hierarchical)', function () {
    // FakeEditor is the blocking synthesis (join) worker; the two fan-out branches
    // stream via DurableBranchAdvancer.
    FakeEditor::fake(fn (): string => 'synthesis-out');

    $runId = HierarchicalFanOutStreamingSwarm::make()->dispatchDurable('fan-out-task')->runId;
    $manager = app(DurableSwarmManager::class);

    // Pin durable_streaming on the run row — what #311's allow-list entry will do at
    // dispatch for StaticHierarchical. The fan-out branch wiring under test is #312's.
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['durable_streaming' => true]);

    $barrierCount = fn (): int => DB::table('swarm_stream_events')->where('run_id', $runId)
        ->where('event_type', 'swarm_causal_seal_barrier')->count();

    // Walk the plan: the coordinator routes to the fan-out, the two branches stream as
    // independent durable jobs (each under its NON-null node_id — the branch_id fallback
    // is never used here), and the join releases the synthesis worker.
    $sawWaiting = false;
    $sealBeforeBranches = null;
    $sealAfterBranches = null;
    $guard = 0;

    while (! in_array(($current = $manager->find($runId))['status'], ['completed', 'failed', 'cancelled'], true)) {
        if ($guard++ > 50) {
            throw new RuntimeException('Hierarchical fan-out streaming run did not converge.');
        }

        if ($current['status'] === 'waiting') {
            $sawWaiting = true;
            // The barrier count just before the branches commit (the parent's prior
            // hierarchical checkpoints may already have sealed earlier nodes).
            $sealBeforeBranches ??= $barrierCount();

            foreach (app(DurableRunStore::class)->branchesFor($runId, $current['current_node_id']) as $branch) {
                if (in_array(($branch['status'] ?? null), ['pending', null], true)) {
                    (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
                }
            }

            // Committing the branches must add NO new barrier (seal-on-join, gate H2):
            // the branch advancer never seals, so the branch events stay above the last
            // barrier — unsealed and retractable — until the post-join checkpoint.
            $sealAfterBranches ??= $barrierCount();

            continue;
        }

        (new AdvanceDurableSwarm($runId, (int) $current['next_step_index']))->handle($manager);
    }

    expect($sawWaiting)->toBeTrue()
        ->and($manager->find($runId)['status'])->toBe('completed')
        // Each fan-out branch streamed under its OWN node id — never the branch_id fallback
        // (which only top-level parallel uses), and never collapsed into one bucket (H3).
        ->and(parallelBranchEventCount($runId, 'research_node'))->toBeGreaterThan(0)
        ->and(parallelBranchEventCount($runId, 'write_node'))->toBeGreaterThan(0)
        // Branch commit added no barrier (seal-on-join, gate H2)...
        ->and($sealAfterBranches)->toBe($sealBeforeBranches)
        // ...but the post-join hierarchical checkpoint sealed the branch generation.
        ->and($barrierCount())->toBeGreaterThan($sealAfterBranches);
});

test('a streaming child swarm pins durable_streaming from its OWN class, independent of the non-streaming parent (#312 Phase 4)', function () {
    FlakyStreamEditor::reset(failuresBeforeSuccess: 0);

    // Non-streaming sequential parent dispatches a #[DurableStreaming] parallel child.
    $parentRunId = FakeSequentialSwarm::make()->dispatchDurable('parent-task')->runId;
    $child = app(DurableSwarmManager::class)->dispatchChildSwarm(
        $parentRunId,
        ParallelDurableStreamingSwarm::class,
        'child-task',
    );

    // The child self-gates through the forcing-function allow-list on ITS OWN topology +
    // attribute (DurableChildSwarmCoordinator → SwarmRunner::dispatchDurable($child)), and
    // pins durable_streaming from the child swarm class — not inherited from the parent.
    expect((bool) DB::table('swarm_durable_runs')->where('run_id', $child->childRunId)->value('durable_streaming'))->toBeTrue()
        ->and((bool) DB::table('swarm_durable_runs')->where('run_id', $parentRunId)->value('durable_streaming'))->toBeFalse();

    // And the child actually streams per-branch when advanced.
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($child->childRunId, 0))->handle($manager);
    (new AdvanceDurableBranch($child->childRunId, 'parallel:0'))->handle($manager);
    (new AdvanceDurableBranch($child->childRunId, 'parallel:1'))->handle($manager);

    expect(parallelBranchEventCount($child->childRunId, 'parallel:0'))->toBeGreaterThan(0)
        ->and(parallelBranchEventCount($child->childRunId, 'parallel:1'))->toBeGreaterThan(0);
});
