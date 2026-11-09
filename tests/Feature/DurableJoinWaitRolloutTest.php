<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\DurableDispatchType;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FailingPromptAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalParallelFailBranchSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\StrandedJoinShapeBreakers;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Rollout of the open-wait join rule (#615). Before #600, closing the last wait of a
 * queued-hierarchical run still at its parallel boundary moved it to durable
 * `pending` and history `pending` and pushed an ordinary advance job. The run then
 * finished only if that job kept being delivered — an ordinary job drives a
 * queued-hierarchical run through the step-durable path — and was stranded for good
 * once it was lost. swarm:recover repairs that shape back to the boundary #600's
 * closers leave, and an ordinary advance job delivered for a queued-hierarchical run
 * is now ignored.
 *
 * Each stranded run here is built faithfully: the current closer (signal, wait
 * timeout or child outcome) closes the wait while the branches still run, which
 * writes every outcome the old closer wrote, and then the old closer's own
 * differences are applied on top — durable and history `pending`.
 */
/** Throws while restoring one run's history, to prove the repair rolls back. */
class RolloutThrowingHistoryStore extends DatabaseRunHistoryStore
{
    public static ?string $throwFor = null;

    public function restoreQueuedHierarchicalBoundaryWait(string $runId): bool
    {
        if ($runId === self::$throwFor) {
            throw new RuntimeException('history write failed');
        }

        return parent::restoreQueuedHierarchicalBoundaryWait($runId);
    }
}

/** A bundled store whose stranded-join selector fails, to prove the sweep goes on. */
class RolloutThrowingSelectorStore extends DatabaseDurableRunStore
{
    public function strandedQueuedHierarchicalJoins(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array
    {
        throw new RuntimeException('selector failed');
    }
}

/** An outbox whose settle() throws, to prove an ignored advance job tolerates it. */
class RolloutThrowingSettleOutbox extends DatabaseDurableOutbox
{
    public static bool $throws = false;

    public function settle(int $entryId, ?string $runId = null): void
    {
        if (self::$throws) {
            throw new RuntimeException('settle failed');
        }

        parent::settle($entryId, $runId);
    }
}

function rolloutRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');
    // Branch and resume jobs never run on their own; each test drives them.
    config()->set('queue.default', 'null');
    config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(QueuedHierarchicalCoordinator::class);
    app()->forgetInstance(DurableSwarmManager::class);
}

function rolloutPlan(): array
{
    return [
        'start_at' => 'parallel_node',
        'nodes' => [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
            'editor_node' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor-branch'],
            'finish_node' => ['type' => 'finish', 'output_from' => 'editor_node'],
        ],
    ];
}

/**
 * Two parallel groups with an ordinary worker between them: the QH continuation
 * reaches a second boundary whose next step index it computes from the history.
 */
function rolloutTwoGroupPlan(): array
{
    return [
        'start_at' => 'group_a',
        'nodes' => [
            'group_a' => ['type' => 'parallel', 'branches' => ['writer_a', 'editor_a'], 'next' => 'researcher_node'],
            'writer_a' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-a'],
            'editor_a' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor-a'],
            'researcher_node' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'between', 'next' => 'group_b'],
            'group_b' => ['type' => 'parallel', 'branches' => ['writer_b', 'editor_b'], 'next' => 'finish_node'],
            'writer_b' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-b'],
            'editor_b' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor-b'],
            'finish_node' => ['type' => 'finish', 'output_from' => 'editor_b'],
        ],
    ];
}

function rolloutManager(): DurableSwarmManager
{
    return app(DurableSwarmManager::class);
}

function rolloutDurable(string $runId): array
{
    return (array) DB::table('swarm_durable_runs')->where('run_id', $runId)->first();
}

function rolloutHistory(string $runId): array
{
    return (array) DB::table('swarm_run_histories')->where('run_id', $runId)->first();
}

/**
 * @return array<int, array{dispatch_type: string, payload: array<string, mixed>, queue_connection: ?string, queue_name: ?string}>
 */
function rolloutOutbox(string $runId, ?DurableDispatchType $type = null): array
{
    return DB::table('swarm_durable_outbox')
        ->where('run_id', $runId)
        ->when($type !== null, fn ($query) => $query->where('dispatch_type', $type->value))
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => [
            'dispatch_type' => (string) $row->dispatch_type,
            'payload' => json_decode((string) $row->payload, true),
            'queue_connection' => $row->queue_connection,
            'queue_name' => $row->queue_name,
        ])
        ->all();
}

/** A queued-hierarchical multi_worker run fanned out to its first boundary. */
function rolloutQueuedRun(string $runId, string $swarmClass = FakeHierarchicalFullSwarm::class): void
{
    $context = RunContext::from('queued-hierarchical-task', $runId);
    (new InvokeSwarm($swarmClass, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    expect(rolloutDurable($runId)['status'])->toBe('waiting')
        ->and(rolloutHistory($runId)['status'])->toBe('waiting');
}

function rolloutCompleteBranches(string $runId, string $nodeId): void
{
    foreach (app(DurableRunStore::class)->branchesFor($runId, $nodeId) as $branch) {
        (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle(rolloutManager());
    }
}

/**
 * Close the run's only wait with the current closer while its branches still run
 * (the run stays waiting at its boundary and every outcome is written), then apply
 * the pre-#600 closer's differences: durable `pending`, history `pending`. No step
 * job is left: its chain is lost.
 */
function rolloutStrand(string $runId, string $origin): void
{
    $manager = rolloutManager();

    if ($origin === 'signal') {
        $manager->wait($runId, 'approval', 'needs approval');
        expect($manager->signal($runId, 'approval', ['ok' => true])->accepted)->toBeTrue();
    } elseif ($origin === 'timeout') {
        $manager->wait($runId, 'approval', 'needs approval', timeoutSeconds: 60);
        test()->travel(2)->minutes();
        Artisan::call('swarm:recover');
        expect(app(ContextStore::class)->find($runId)['metadata']['durable_wait_outcomes']['approval']['status'])->toBe('timed_out');
    } else {
        $childRunId = $manager->dispatchChildSwarm($runId, FakeSequentialSwarm::class, 'child-task')->childRunId;

        foreach ([0, 1, 2] as $step) {
            (new AdvanceDurableSwarm($childRunId, $step))->handle($manager);
        }

        expect($manager->find($childRunId)['status'])->toBe('completed')
            ->and(DB::table('swarm_durable_child_runs')->where('child_run_id', $childRunId)->value('terminal_event_dispatched_at'))->not->toBeNull();
    }

    expect(rolloutDurable($runId)['status'])->toBe('waiting')
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('status', 'waiting')->count())->toBe(0);

    DB::table('swarm_durable_runs')->where('run_id', $runId)->update([
        'status' => 'pending',
        'wait_reason' => null,
        'waiting_since' => null,
        'wait_timeout_at' => null,
    ]);
    DB::table('swarm_run_histories')->where('run_id', $runId)->update(['status' => 'pending']);
}

/**
 * What $polls deliveries of the stray ordinary job leave behind on the old code: each
 * took the durable lease, stamped the current step, checkpointed one step further
 * and left the history lease it wrote. Written directly: on the new code that job
 * is ignored before it can do any of this.
 */
function rolloutPoll(string $runId, int $polls): void
{
    if ($polls === 0) {
        return;
    }

    $next = (int) rolloutDurable($runId)['next_step_index'];

    DB::table('swarm_durable_runs')->where('run_id', $runId)->update([
        'next_step_index' => $next + $polls,
        'current_step_index' => $next + $polls - 1,
        'attempts' => DB::raw('attempts + '.$polls),
    ]);
    DB::table('swarm_run_histories')->where('run_id', $runId)->update([
        'leased_until' => now('UTC')->addMinutes(5),
    ]);
}

function rolloutAge(string $runId): void
{
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['updated_at' => now('UTC')->subMinutes(10)]);
}

function rolloutResume(string $runId): void
{
    (new ResumeQueuedHierarchicalSwarm($runId))->handle(app(QueuedHierarchicalCoordinator::class));
}

/**
 * Undo acquireQueuedRunContinuationLease() as a continuation that never ran would
 * leave it: history back to waiting at the boundary, no lease, flag set again.
 */
function rolloutHandBackContinuationLease(string $runId): void
{
    $metadata = json_decode((string) rolloutHistory($runId)['metadata'], true);
    $metadata['queue_hierarchical_waiting_parallel'] = true;

    DB::table('swarm_run_histories')->where('run_id', $runId)->update([
        'status' => 'waiting',
        'execution_token' => null,
        'leased_until' => null,
        'metadata' => json_encode($metadata),
    ]);
}

/** The stranded run's own rows, for "untouched" assertions. */
function rolloutSnapshot(string $runId): array
{
    $durable = rolloutDurable($runId);
    $history = rolloutHistory($runId);

    return [
        'durable' => $durable,
        'history' => $history,
        'outbox' => rolloutOutbox($runId),
        'waits' => DB::table('swarm_durable_waits')->where('run_id', $runId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

beforeEach(function () {
    rolloutRuntime();
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

afterEach(function () {
    RolloutThrowingHistoryStore::$throwFor = null;
    RolloutThrowingSettleOutbox::$throws = false;
});

describe('swarm:recover repairs a queued-hierarchical run stranded by a pre-upgrade closer', function () {
    beforeEach(function () {
        FakeHierarchicalCoordinator::fake([rolloutPlan()]);
        FakeResearcher::fake(['research-out', 'child-research']);
        FakeWriter::fake(['writer-out', 'child-writer']);
        FakeEditor::fake(['editor-out', 'child-editor']);
    });

    test('a stranded run whose branches finished is released through its own continuation and completes once', function (string $origin, int $polls) {
        $runId = "rollout-done-{$origin}-{$polls}";
        rolloutQueuedRun($runId);
        rolloutStrand($runId, $origin);
        rolloutPoll($runId, $polls);
        rolloutCompleteBranches($runId, 'parallel_node');

        // The branches finished, but the old shape is pending: nothing released the join.
        expect(rolloutDurable($runId)['status'])->toBe('pending')
            ->and(rolloutHistory($runId)['status'])->toBe('pending')
            ->and(rolloutOutbox($runId, DurableDispatchType::QueuedResume))->toBe([]);

        rolloutAge($runId);
        $metadataBefore = app(ContextStore::class)->find($runId)['metadata'];
        Artisan::call('swarm:recover');

        $durable = rolloutDurable($runId);
        $resumes = rolloutOutbox($runId, DurableDispatchType::QueuedResume);
        expect($durable['status'])->toBe('pending')
            ->and($durable['current_step_index'])->toBeNull()
            ->and($durable['execution_token'])->toBeNull()
            ->and(rolloutHistory($runId)['status'])->toBe('waiting')
            ->and(rolloutHistory($runId)['leased_until'])->toBeNull()
            ->and($resumes)->toHaveCount(1)
            // Routed as the run is: the release's own routing, as every join release.
            ->and($resumes[0]['queue_connection'])->toBe($durable['queue_connection'])
            ->and($resumes[0]['queue_name'])->toBe($durable['queue_name'])
            ->and(rolloutOutbox($runId, DurableDispatchType::Step))->toBe([]);

        // The repair keeps every outcome the closer wrote.
        $metadataAfter = app(ContextStore::class)->find($runId)['metadata'];
        foreach (['durable_wait_outcomes', 'durable_signals', 'durable_child_runs'] as $key) {
            expect($metadataAfter[$key] ?? null)->toBe($metadataBefore[$key] ?? null);
        }

        rolloutResume($runId);

        // The finish node reads the editor branch's own output (the child swarm of the
        // child origin ran its editor first, so the fakes' order differs per origin).
        $editorOutput = collect(app(DurableRunStore::class)->branchesFor($runId, 'parallel_node'))->firstWhere('node_id', 'editor_node')['output'];
        expect($editorOutput)->toBeIn(['editor-out', 'child-editor'])
            ->and(rolloutHistory($runId)['status'])->toBe('completed')
            ->and(rolloutHistory($runId)['output'])->toBe($editorOutput)
            ->and(rolloutDurable($runId)['status'])->toBe('completed');

        // A second sweep has nothing left to repair.
        Artisan::call('swarm:recover');
        expect(rolloutOutbox($runId, DurableDispatchType::QueuedResume))->toHaveCount(1);
    })->with(['signal', 'timeout', 'child'])->with(['no stray polls' => 0, 'three stray polls' => 3]);

    test('a stranded run whose branches still run is put back at its boundary; the last branch releases it', function () {
        $runId = 'rollout-running';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        rolloutPoll($runId, 3);
        rolloutAge($runId);

        Artisan::call('swarm:recover');

        expect(rolloutDurable($runId)['status'])->toBe('waiting')
            ->and(rolloutDurable($runId)['current_step_index'])->toBeNull()
            ->and(rolloutHistory($runId)['status'])->toBe('waiting')
            ->and(rolloutOutbox($runId, DurableDispatchType::QueuedResume))->toBe([]);

        rolloutCompleteBranches($runId, 'parallel_node');

        expect(rolloutDurable($runId)['status'])->toBe('pending')
            ->and(rolloutOutbox($runId, DurableDispatchType::QueuedResume))->toHaveCount(1);

        rolloutResume($runId);

        expect(rolloutHistory($runId)['status'])->toBe('completed')
            ->and(rolloutHistory($runId)['output'])->toBe('editor-out');
    });

    test('a repaired run is held by the released-join wait guard once its join is released', function () {
        $runId = 'rollout-wait-guard';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        rolloutPoll($runId, 3);
        rolloutCompleteBranches($runId, 'parallel_node');
        rolloutAge($runId);

        Artisan::call('swarm:recover');

        expect(rolloutDurable($runId)['status'])->toBe('pending');

        // A wait opened after the join was released is refused, as on any released join:
        // the stamped current step the stray polls left would have let it through.
        expect(fn () => rolloutManager()->wait($runId, 'late', 'too late'))->toThrow(SwarmException::class, 'its branch join has already been released');
        expect(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', 'late')->exists())->toBeFalse();
    });

    test('a pause or cancel request on a stranded run does not stop its repair', function (string $column) {
        $runId = "rollout-flag-{$column}";
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        DB::table('swarm_durable_runs')->where('run_id', $runId)->update([$column => now('UTC')]);
        rolloutAge($runId);

        Artisan::call('swarm:recover');

        expect(rolloutDurable($runId)['status'])->toBe('waiting')
            ->and(rolloutHistory($runId)['status'])->toBe('waiting');
    })->with(['pause_requested_at', 'cancel_requested_at']);

    test('the repair sets the waiting-parallel flag on history without touching its other metadata', function (string $flag) {
        $runId = "rollout-flag-meta-{$flag}";
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        rolloutCompleteBranches($runId, 'parallel_node');

        $metadata = json_decode((string) rolloutHistory($runId)['metadata'], true);
        $metadata['rollout_marker'] = 'kept';

        if ($flag === 'absent') {
            unset($metadata['queue_hierarchical_waiting_parallel']);
        } else {
            $metadata['queue_hierarchical_waiting_parallel'] = false;
        }

        DB::table('swarm_run_histories')->where('run_id', $runId)->update(['metadata' => json_encode($metadata)]);
        rolloutAge($runId);

        Artisan::call('swarm:recover');

        $after = json_decode((string) rolloutHistory($runId)['metadata'], true);
        expect($after['queue_hierarchical_waiting_parallel'])->toBeTrue()
            ->and($after['rollout_marker'])->toBe('kept');

        rolloutResume($runId);

        expect(rolloutHistory($runId)['status'])->toBe('completed');
    })->with(['absent', 'false']);

    test('a fail_run branch failure on a stranded run fails the parent instead of joining it', function () {
        config()->set('swarm.durable.parallel.failure_policy', 'fail_run');
        FakeHierarchicalCoordinator::fake([[
            'start_at' => 'parallel_node',
            'nodes' => [
                'parallel_node' => ['type' => 'parallel', 'branches' => ['writer_node', 'failing_node'], 'next' => 'finish_node'],
                'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
                'failing_node' => ['type' => 'worker', 'agent' => FailingPromptAgent::class, 'prompt' => 'failing-branch'],
                'finish_node' => ['type' => 'finish', 'output_from' => 'writer_node'],
            ],
        ]]);
        $runId = 'rollout-fail-run';
        rolloutQueuedRun($runId, FakeHierarchicalParallelFailBranchSwarm::class);
        rolloutStrand($runId, 'signal');

        foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel_node') as $branch) {
            if ($branch['agent_class'] === FailingPromptAgent::class) {
                // Its worker failed the branch but never reached the parent.
                DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', $branch['branch_id'])->update([
                    'status' => 'failed',
                    'failure' => json_encode(['message' => 'branch failed', 'class' => RuntimeException::class]),
                    'finished_at' => now('UTC'),
                ]);

                continue;
            }

            (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle(rolloutManager());
        }

        rolloutAge($runId);

        Artisan::call('swarm:recover');

        expect(rolloutDurable($runId)['status'])->toBe('failed')
            ->and(rolloutHistory($runId)['status'])->toBe('failed')
            ->and(rolloutOutbox($runId, DurableDispatchType::QueuedResume))->toBe([]);
    });

    test('with a limit of one, an older run the repair cannot take does not crowd out a newer stranded run', function () {
        rolloutQueuedRun('rollout-old-leased');
        rolloutStrand('rollout-old-leased', 'signal');
        DB::table('swarm_durable_runs')->where('run_id', 'rollout-old-leased')->update([
            'leased_until' => now('UTC')->addMinutes(5),
            'execution_token' => 'live-step',
            'updated_at' => now('UTC')->subMinutes(20),
        ]);

        FakeHierarchicalCoordinator::fake([rolloutPlan()]);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
        rolloutQueuedRun('rollout-new');
        rolloutStrand('rollout-new', 'signal');
        rolloutAge('rollout-new');

        Artisan::call('swarm:recover', ['--limit' => 1]);

        expect(rolloutDurable('rollout-new')['status'])->toBe('waiting')
            ->and(rolloutDurable('rollout-old-leased')['status'])->toBe('pending')
            ->and(rolloutDurable('rollout-old-leased')['execution_token'])->toBe('live-step');
    });

    test('scoped by run id, the repair leaves other stranded runs alone', function () {
        rolloutQueuedRun('rollout-scope-a');
        rolloutStrand('rollout-scope-a', 'signal');
        rolloutAge('rollout-scope-a');

        FakeHierarchicalCoordinator::fake([rolloutPlan()]);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
        rolloutQueuedRun('rollout-scope-b');
        rolloutStrand('rollout-scope-b', 'signal');
        rolloutAge('rollout-scope-b');

        Artisan::call('swarm:recover', ['--run-id' => 'rollout-scope-b']);

        expect(rolloutDurable('rollout-scope-a')['status'])->toBe('pending')
            ->and(rolloutDurable('rollout-scope-b')['status'])->toBe('waiting');
    });

    test('scoped by swarm class, the repair takes only runs of that swarm', function () {
        rolloutQueuedRun('rollout-swarm-scope');
        rolloutStrand('rollout-swarm-scope', 'signal');
        rolloutAge('rollout-swarm-scope');

        Artisan::call('swarm:recover', ['--swarm' => FakeSequentialSwarm::class]);
        expect(rolloutDurable('rollout-swarm-scope')['status'])->toBe('pending');

        Artisan::call('swarm:recover', ['--swarm' => FakeHierarchicalFullSwarm::class]);
        expect(rolloutDurable('rollout-swarm-scope')['status'])->toBe('waiting');
    });
});

describe('the repair never touches a healthy run', function () {
    beforeEach(function () {
        FakeHierarchicalCoordinator::fake([rolloutPlan()]);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
    });

    test('a queued-hierarchical run released to its join, its resume queued', function () {
        $runId = 'healthy-released';
        rolloutQueuedRun($runId);
        rolloutCompleteBranches($runId, 'parallel_node');
        expect(rolloutDurable($runId)['status'])->toBe('pending')
            ->and(rolloutHistory($runId)['status'])->toBe('waiting');
        rolloutAge($runId);
        $before = rolloutSnapshot($runId);

        Artisan::call('swarm:recover');

        expect(rolloutSnapshot($runId)['durable'])->toBe($before['durable'])
            ->and(rolloutSnapshot($runId)['history'])->toBe($before['history']);
    });

    test('a queued-hierarchical continuation holding its history lease', function () {
        $runId = 'healthy-running';
        rolloutQueuedRun($runId);
        rolloutCompleteBranches($runId, 'parallel_node');
        DB::table('swarm_run_histories')->where('run_id', $runId)->update([
            'status' => 'running',
            'execution_token' => 'continuation',
            'leased_until' => now('UTC')->addMinutes(5),
        ]);
        rolloutAge($runId);
        $before = rolloutSnapshot($runId);

        Artisan::call('swarm:recover');

        expect(rolloutSnapshot($runId)['durable'])->toBe($before['durable'])
            ->and(rolloutSnapshot($runId)['history'])->toBe($before['history']);
    });

    test('an old-shape run whose stray step still holds the durable lease, or that moved within the grace period', function (string $case) {
        $runId = "healthy-{$case}";
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');

        if ($case === 'leased') {
            rolloutAge($runId);
            DB::table('swarm_durable_runs')->where('run_id', $runId)->update([
                'leased_until' => now('UTC')->addMinutes(5),
                'execution_token' => 'live-step',
            ]);
        }

        $before = rolloutSnapshot($runId);

        Artisan::call('swarm:recover');

        expect(rolloutSnapshot($runId)['durable'])->toBe($before['durable'])
            ->and(rolloutSnapshot($runId)['history'])->toBe($before['history']);
    })->with(['leased', 'recent']);

    test('an old-shape run with an open wait, or whose current node has no branches', function (string $case) {
        $runId = "healthy-{$case}";
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');

        if ($case === 'open-wait') {
            DB::table('swarm_durable_waits')->insert([
                'run_id' => $runId, 'name' => 'still-open', 'status' => 'waiting',
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        } else {
            DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['current_node_id' => 'finish_node']);
        }

        rolloutAge($runId);
        $before = rolloutSnapshot($runId);

        Artisan::call('swarm:recover');

        expect(rolloutSnapshot($runId)['durable'])->toBe($before['durable'])
            ->and(rolloutSnapshot($runId)['history'])->toBe($before['history']);
    })->with(['open-wait', 'no-branches']);

    test('a step-durable run that is pending with a queued step', function () {
        $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
        DB::table('swarm_run_histories')->where('run_id', $runId)->update(['status' => 'pending']);
        rolloutAge($runId);
        $durableBefore = rolloutDurable($runId);
        $historyBefore = rolloutHistory($runId);

        Artisan::call('swarm:recover');

        $durableAfter = rolloutDurable($runId);
        // Ordinary recovery may redispatch it; the repair never moves it to waiting.
        expect($durableAfter['status'])->toBe($durableBefore['status'])
            ->and($durableAfter['current_node_id'])->toBe($durableBefore['current_node_id'])
            ->and(rolloutHistory($runId)['status'])->toBe($historyBefore['status']);
    });
});

describe('an ordinary advance job delivered for a queued-hierarchical run is ignored', function () {

    test('it never takes the durable lease and settles the outbox entry it carries', function () {
        FakeHierarchicalCoordinator::fake([rolloutPlan()]);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
        $runId = 'stray-eager';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');

        $durable = rolloutDurable($runId);
        $entryId = app(DurableOutbox::class)->enqueueStep($runId, (int) $durable['next_step_index'], 'durable-test', 'swarm-durable', true);
        $before = rolloutSnapshot($runId);

        (new AdvanceDurableSwarm($runId, (int) $durable['next_step_index'], outboxEntryId: $entryId))->handle(rolloutManager());

        $after = rolloutSnapshot($runId);
        expect($after['durable'])->toBe($before['durable'])
            ->and($after['history'])->toBe($before['history'])
            ->and(DB::table('swarm_durable_outbox')->where('id', $entryId)->exists())->toBeFalse();
    });

    test('a relay delivery (no entry id) changes nothing, and a duplicate delivery is harmless', function () {
        FakeHierarchicalCoordinator::fake([rolloutPlan()]);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
        $runId = 'stray-relay';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        $index = (int) rolloutDurable($runId)['next_step_index'];
        $before = rolloutSnapshot($runId);

        (new AdvanceDurableSwarm($runId, $index))->handle(rolloutManager());
        (new AdvanceDurableSwarm($runId, $index))->handle(rolloutManager());

        expect(rolloutSnapshot($runId))->toBe($before);
    });

    test('it says so in one warning naming the run, and a settle that throws changes nothing', function () {
        FakeHierarchicalCoordinator::fake([rolloutPlan()]);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
        $runId = 'stray-logged';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        $index = (int) rolloutDurable($runId)['next_step_index'];
        $entryId = app(DurableOutbox::class)->enqueueStep($runId, $index, 'durable-test', 'swarm-durable', true);
        $before = rolloutSnapshot($runId);

        $warnings = [];
        $logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$warnings): void {
            $warnings[] = [$message, $context];
        });
        app()->instance(LoggerInterface::class, $logger);
        RolloutThrowingSettleOutbox::$throws = true;
        app()->bind(DurableOutbox::class, RolloutThrowingSettleOutbox::class);
        app()->forgetInstance(DurableSwarmManager::class);

        (new AdvanceDurableSwarm($runId, $index, outboxEntryId: $entryId))->handle(rolloutManager());

        $ignored = array_values(array_filter($warnings, fn (array $warning): bool => str_contains($warning[0], 'ignored an ordinary advance job')));
        expect(rolloutSnapshot($runId))->toBe($before)
            ->and(DB::table('swarm_durable_outbox')->where('id', $entryId)->exists())->toBeTrue()
            ->and($ignored)->toHaveCount(1)
            ->and($ignored[0][1])->toMatchArray([
                'run_id' => $runId,
                'swarm_class' => FakeHierarchicalFullSwarm::class,
                'status' => 'pending',
                'current_node_id' => 'parallel_node',
                'step_index' => $index,
                'outbox_entry_id' => $entryId,
                'outbox_entry_settled' => false,
            ]);
    });

    test('a delayed stray job stays harmless across a later boundary and while the continuation holds its lease', function () {
        FakeHierarchicalCoordinator::fake([rolloutTwoGroupPlan()]);
        FakeWriter::fake(['writer-a-out', 'writer-b-out']);
        FakeEditor::fake(['editor-a-out', 'editor-b-out']);
        FakeResearcher::fake(['between-out']);
        $runId = 'stray-two-groups';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        rolloutCompleteBranches($runId, 'group_a');

        $firstBoundaryIndex = (int) rolloutDurable($runId)['next_step_index'];
        // The delayed stray job carries an index the second boundary reaches (asserted
        // below), so only the profile guard — no index — can keep it out.
        $secondBoundaryIndex = $firstBoundaryIndex + 3;
        rolloutPoll($runId, $secondBoundaryIndex - $firstBoundaryIndex);
        $stray = fn () => (new AdvanceDurableSwarm($runId, $secondBoundaryIndex))->handle(rolloutManager());
        rolloutAge($runId);

        Artisan::call('swarm:recover');
        expect(rolloutDurable($runId)['status'])->toBe('pending');

        // Delivered after the release, before the continuation runs.
        $before = rolloutSnapshot($runId);
        $stray();
        expect(rolloutSnapshot($runId))->toBe($before);

        // Continuation: joins group_a, runs the researcher, fans out group_b.
        rolloutResume($runId);
        expect(rolloutDurable($runId)['status'])->toBe('waiting')
            ->and(rolloutDurable($runId)['current_node_id'])->toBe('group_b')
            ->and((int) rolloutDurable($runId)['next_step_index'])->toBe($secondBoundaryIndex);

        rolloutCompleteBranches($runId, 'group_b');
        expect(rolloutDurable($runId)['status'])->toBe('pending');

        // Delivered at the second released boundary: its index now matches the row.
        $before = rolloutSnapshot($runId);
        $stray();
        expect(rolloutSnapshot($runId))->toBe($before);

        // Delivered while the continuation holds its history lease.
        app(DatabaseRunHistoryStore::class)->acquireQueuedRunContinuationLease($runId, 3600, 300);
        $before = rolloutSnapshot($runId);
        $stray();
        expect(rolloutSnapshot($runId))->toBe($before);
        rolloutHandBackContinuationLease($runId);

        rolloutResume($runId);
        expect(rolloutHistory($runId)['status'])->toBe('completed')
            ->and(rolloutHistory($runId)['output'])->toBe('editor-b-out');
    });
});

describe('parentsWaitingOnTerminalChildren and an already reconciled parent', function () {
    beforeEach(function () {
        FakeHierarchicalCoordinator::fake([rolloutPlan(), rolloutPlan()]);
        FakeResearcher::fake(['r1', 'r2', 'r3', 'r4', 'r5', 'r6']);
        FakeWriter::fake(['writer-out', 'writer-out', 'w1', 'w2', 'w3', 'w4']);
        FakeEditor::fake(['editor-out', 'editor-out', 'e1', 'e2', 'e3', 'e4']);
    });

    test('a parent whose child outcome was delivered is not selected again while its branches run', function () {
        $manager = rolloutManager();
        rolloutQueuedRun('reconciled-parent');
        $child = $manager->dispatchChildSwarm('reconciled-parent', FakeSequentialSwarm::class, 'child-task')->childRunId;

        foreach ([0, 1, 2] as $step) {
            (new AdvanceDurableSwarm($child, $step))->handle($manager);
        }

        expect(rolloutDurable('reconciled-parent')['status'])->toBe('waiting');

        $store = app(DatabaseDurableRunStore::class);
        expect(collect($store->parentsWaitingOnTerminalChildren())->pluck('run_id')->all())->toBe([]);
    });

    test('a reconciled parent cannot crowd out a parent that still needs its child outcome', function () {
        $manager = rolloutManager();
        rolloutQueuedRun('reconciled-first');
        $reconciled = $manager->dispatchChildSwarm('reconciled-first', FakeSequentialSwarm::class, 'child-task')->childRunId;

        foreach ([0, 1, 2] as $step) {
            (new AdvanceDurableSwarm($reconciled, $step))->handle($manager);
        }

        rolloutQueuedRun('needs-reconcile');
        $pending = $manager->dispatchChildSwarm('needs-reconcile', FakeSequentialSwarm::class, 'child-task')->childRunId;
        // The child finished but its outcome never reached the parent.
        DB::table('swarm_durable_child_runs')->where('child_run_id', $pending)->update([
            'status' => 'completed',
            'updated_at' => now('UTC')->addMinute(),
        ]);

        Artisan::call('swarm:recover', ['--limit' => 1]);

        expect(DB::table('swarm_durable_waits')->where('run_id', 'needs-reconcile')->where('status', 'waiting')->count())->toBe(0)
            ->and(DB::table('swarm_durable_child_runs')->where('child_run_id', $pending)->value('terminal_event_dispatched_at'))->not->toBeNull();
    });
});

dataset('durable shape breakers', fn () => array_map(fn (string $key) => [$key], array_combine(array_keys(StrandedJoinShapeBreakers::durable()), array_keys(StrandedJoinShapeBreakers::durable()))));
dataset('history shape breakers', fn () => array_map(fn (string $key) => [$key], array_combine(array_keys(StrandedJoinShapeBreakers::history()), array_keys(StrandedJoinShapeBreakers::history()))));
dataset('all shape breakers', fn () => array_map(fn (string $key) => [$key], array_combine(array_keys(StrandedJoinShapeBreakers::all()), array_keys(StrandedJoinShapeBreakers::all()))));

describe('the stranded shape, condition by condition', function () {
    beforeEach(function () {
        FakeHierarchicalCoordinator::fake([rolloutPlan()]);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
    });

    test('the selector takes a stranded run, also one a dead stray step left with an expired lease', function (bool $deadLease) {
        $runId = 'shape-select';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        rolloutAge($runId);

        if ($deadLease) {
            StrandedJoinShapeBreakers::expireDeadLease($runId);
        }

        expect(app(DatabaseDurableRunStore::class)->strandedQueuedHierarchicalJoins(graceSeconds: 300))
            ->toBe([['run_id' => $runId, 'swarm_class' => FakeHierarchicalFullSwarm::class, 'current_node_id' => 'parallel_node']]);
    })->with(['no lease' => false, 'expired dead lease' => true]);

    test('the selector takes nothing that breaks one condition of the shape', function (string $breaker) {
        $runId = 'shape-select-broken';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        rolloutAge($runId);
        StrandedJoinShapeBreakers::all()[$breaker]($runId);

        expect(app(DatabaseDurableRunStore::class)->strandedQueuedHierarchicalJoins(graceSeconds: 300))->toBe([]);
    })->with('all shape breakers');

    test('the restore rejects a broken durable condition under its lock before it touches the history row', function (string $breaker) {
        $runId = 'shape-restore-durable';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        rolloutAge($runId);
        StrandedJoinShapeBreakers::durable()[$breaker]($runId);
        $before = rolloutSnapshot($runId);
        $historyCalled = false;

        $restored = app(DatabaseDurableRunStore::class)->restoreStrandedQueuedHierarchicalJoin($runId, 300, function () use (&$historyCalled): bool {
            $historyCalled = true;

            return true;
        });

        expect($restored)->toBeFalse()
            ->and($historyCalled)->toBeFalse()
            ->and(rolloutSnapshot($runId))->toBe($before);
    })->with('durable shape breakers');

    test('the restore rejects a broken history condition and writes nothing', function (string $breaker) {
        $runId = 'shape-restore-history';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        rolloutAge($runId);
        StrandedJoinShapeBreakers::history()[$breaker]($runId);
        $before = rolloutSnapshot($runId);

        $restored = app(DatabaseDurableRunStore::class)->restoreStrandedQueuedHierarchicalJoin(
            $runId,
            300,
            fn (): bool => app(DatabaseRunHistoryStore::class)->restoreQueuedHierarchicalBoundaryWait($runId),
        );

        expect($restored)->toBeFalse()
            ->and(rolloutSnapshot($runId))->toBe($before);
    })->with('history shape breakers');

    test('the repair clears a dead stray step\'s lease and token and the run completes', function () {
        $runId = 'shape-dead-lease';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        rolloutCompleteBranches($runId, 'parallel_node');
        rolloutAge($runId);
        StrandedJoinShapeBreakers::expireDeadLease($runId);

        Artisan::call('swarm:recover');

        expect(rolloutDurable($runId)['execution_token'])->toBeNull()
            ->and(rolloutDurable($runId)['leased_until'])->toBeNull()
            ->and(rolloutHistory($runId)['status'])->toBe('waiting');

        rolloutResume($runId);

        expect(rolloutHistory($runId)['status'])->toBe('completed');
    });

    test('a history row the restore refuses leaves the durable row as it was', function () {
        $runId = 'shape-history-refused';
        rolloutQueuedRun($runId);
        rolloutStrand($runId, 'signal');
        rolloutAge($runId);
        $before = rolloutSnapshot($runId);

        $restored = app(DatabaseDurableRunStore::class)->restoreStrandedQueuedHierarchicalJoin($runId, 300, fn (): bool => false);

        expect($restored)->toBeFalse()
            ->and(rolloutSnapshot($runId))->toBe($before);
    });

    test('a stranded-join selector that fails loses only the repair; the waiting-join release in the same sweep still runs', function () {
        $runId = 'shape-selector-fails';
        rolloutQueuedRun($runId);
        // A healthy run at its boundary whose branches finished but whose join was never
        // released (its worker stopped first): the waiting-join phase releases it.
        DB::table('swarm_durable_branches')->where('run_id', $runId)->update(['status' => 'completed', 'output' => 'branch-out', 'finished_at' => now('UTC')]);
        rolloutAge($runId);

        app()->singleton(DurableRunStore::class, RolloutThrowingSelectorStore::class);
        app()->forgetInstance(DurableSwarmManager::class);

        expect(Artisan::call('swarm:recover'))->toBe(0)
            ->and(rolloutDurable($runId)['status'])->toBe('pending')
            ->and(rolloutOutbox($runId, DurableDispatchType::QueuedResume))->toHaveCount(1);
    });

    test('a repair that throws rolls back and the sweep goes on to the next stranded run', function () {
        rolloutQueuedRun('shape-throws');
        rolloutStrand('shape-throws', 'signal');
        DB::table('swarm_durable_runs')->where('run_id', 'shape-throws')->update(['updated_at' => now('UTC')->subMinutes(20)]);

        FakeHierarchicalCoordinator::fake([rolloutPlan()]);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
        rolloutQueuedRun('shape-next');
        rolloutStrand('shape-next', 'signal');
        rolloutAge('shape-next');
        $before = rolloutSnapshot('shape-throws');

        RolloutThrowingHistoryStore::$throwFor = 'shape-throws';
        app()->bind(DatabaseRunHistoryStore::class, RolloutThrowingHistoryStore::class);
        app()->forgetInstance(DurableSwarmManager::class);

        Artisan::call('swarm:recover');

        expect(rolloutSnapshot('shape-throws'))->toBe($before)
            ->and(rolloutDurable('shape-next')['status'])->toBe('waiting');
    });
});
