<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\DurableDispatchType;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeAgentSettingsDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeAgentSettingsDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableOutbox;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableEagerDispatcher;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManifest;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyDurableAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ChildDispatchingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableWaitAttributeSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ZeroDelayRetryDurableSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ZeroDelayRetryParallelDurableSwarm;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager as TestingTransactionsManager;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

/**
 * Coverage for swarm.durable.eager_dispatch: the advance job is pushed when the
 * swarm transaction that wrote its outbox entry commits, and settles that entry
 * when it acquires its lease. The relay remains the recovery path.
 */
final class EagerRecordingQueue extends NullQueue
{
    /** @var list<object> */
    public static array $pushed = [];

    public static bool $throw = false;

    public function __construct(bool $afterCommit)
    {
        $this->dispatchAfterCommit = $afterCommit;
    }

    /**
     * Goes through Queue::enqueueUsing(), unlike QueueFake, so the framework's
     * after-commit deferral is exercised for real.
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->enqueueUsing($job, '{}', $queue, null, function () use ($job): string {
            if (self::$throw) {
                throw new RuntimeException('queue unavailable');
            }

            self::$pushed[] = $job;

            return 'recorded';
        });
    }
}

function eagerConfigureRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');
    config()->set('database.connections.eager-other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(QueuedHierarchicalCoordinator::class);
    app()->forgetInstance(DurableSwarmManager::class);
}

function eagerOn(): void
{
    config()->set('swarm.durable.eager_dispatch', true);
}

function eagerRegisterRecordingQueue(string $connection, bool $afterCommit): void
{
    config()->set("queue.connections.{$connection}", ['driver' => 'eager-recording', 'after_commit' => $afterCommit]);

    app('queue')->extend('eager-recording', fn (): ConnectorInterface => new class implements ConnectorInterface
    {
        public function connect(array $config)
        {
            return new EagerRecordingQueue((bool) ($config['after_commit'] ?? false));
        }
    });
}

/**
 * @return list<AdvanceDurableSwarm|AdvanceDurableBranch>
 */
function eagerPushedAdvanceJobs(): array
{
    $jobs = [];

    foreach (Queue::pushedJobs() as $pushes) {
        foreach ($pushes as $push) {
            if ($push['job'] instanceof AdvanceDurableSwarm || $push['job'] instanceof AdvanceDurableBranch) {
                $jobs[] = $push['job'];
            }
        }
    }

    return $jobs;
}

/**
 * Jobs pushed directly on commit (they carry the outbox entry they settle).
 *
 * @return list<AdvanceDurableSwarm|AdvanceDurableBranch>
 */
function eagerDirectJobs(?string $runId = null): array
{
    return array_values(array_filter(
        eagerPushedAdvanceJobs(),
        fn (object $job): bool => $job->outboxEntryId !== null && ($runId === null || $job->runId === $runId),
    ));
}

/**
 * @return list<AdvanceDurableSwarm>
 */
function eagerDirectStepJobs(?string $runId = null): array
{
    return array_values(array_filter(eagerDirectJobs($runId), fn (object $job): bool => $job instanceof AdvanceDurableSwarm));
}

/**
 * @return list<AdvanceDurableBranch>
 */
function eagerDirectBranchJobs(?string $runId = null): array
{
    return array_values(array_filter(eagerDirectJobs($runId), fn (object $job): bool => $job instanceof AdvanceDurableBranch));
}

function eagerHealthRelayLine(): string
{
    Artisan::call('swarm:health', ['--durable' => true]);

    foreach (explode("\n", Artisan::output()) as $line) {
        if (str_contains($line, '| Outbox relay ')) {
            return $line;
        }
    }

    return '';
}

function eagerOutboxRow(int $id): ?object
{
    return DB::table('swarm_durable_outbox')->where('id', $id)->first();
}

function eagerOutboxCount(string $runId): int
{
    return DB::table('swarm_durable_outbox')->where('run_id', $runId)->count();
}

function eagerDispatcher(?DatabaseTransactionsManager $transactions, ?DurableJobDispatcher $jobs = null): DurableEagerDispatcher
{
    return new DurableEagerDispatcher(
        DB::connection(),
        app(ConfigRepository::class),
        $jobs ?? app(DurableJobDispatcher::class),
        app(Dispatcher::class),
        $transactions,
    );
}

function eagerNativeJobDispatcher(int $version): DurableJobDispatcher
{
    $contexts = Mockery::mock(ContextStore::class);
    $contexts->shouldReceive('find')->andReturn(['native_input_ref' => 'eager-native-ref']);
    $store = Mockery::mock(NativeInputStore::class);
    $store->shouldReceive('find')->andReturn(['format_version' => $version]);

    return new DurableJobDispatcher(app(ConfigRepository::class), $contexts, $store);
}

/**
 * Serialize a job, then rewrite the payload: drop outboxEntryId (as code from
 * before this change queued it) and/or add a key the class does not declare.
 */
function eagerReserialize(object $job, bool $dropEntryId, bool $addUnknown): object
{
    $serialized = serialize($job);
    $pattern = '/^O:(\d+):"([^"]+)":(\d+):\{/';
    preg_match($pattern, $serialized, $header);
    $count = (int) $header[3];
    $body = substr($serialized, strlen($header[0]));

    if ($dropEntryId) {
        $body = (string) preg_replace('/s:13:"outboxEntryId";(i:\d+|N);/', '', $body, 1, $removed);
        $count -= $removed;
    }

    if ($addUnknown) {
        $body = 's:13:"unknownFuture";i:1;'.$body;
        $count++;
    }

    return unserialize('O:'.$header[1].':"'.$header[2].'":'.$count.':{'.$body);
}

beforeEach(function (): void {
    eagerConfigureRuntime();
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    EagerRecordingQueue::$pushed = [];
    EagerRecordingQueue::$throw = false;
});

// ---------------------------------------------------------------------------
// The outbox contract
// ---------------------------------------------------------------------------

test('enqueueStep and enqueueBranch return the id of the row they insert', function (): void {
    $outbox = app(DurableOutbox::class);
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;

    $step = $outbox->enqueueStep($runId, 1, null, null);
    $branch = $outbox->enqueueBranch($runId, 'parallel:0', null, null);

    expect($step)->not->toBe($branch)
        ->and(json_decode((string) eagerOutboxRow($step)->payload, true))->toBe(['step_index' => 1])
        ->and(json_decode((string) eagerOutboxRow($branch)->payload, true))->toBe(['branch_id' => 'parallel:0']);
});

test('a direct-dispatch row is born reserved: drain skips it for one timeout and health for two', function (): void {
    $this->freezeTime();
    $outbox = app(DurableOutbox::class);
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $direct = $outbox->enqueueStep($runId, 1, null, null, directDispatch: true);
    $relayOnly = $outbox->enqueueStep($runId, 2, null, null);

    expect(eagerOutboxRow($direct)->reserved_at)->not->toBeNull()
        ->and(eagerOutboxRow($relayOnly)->reserved_at)->toBeNull();

    // The relay-only row is drained at once; the direct row is not.
    $first = $outbox->drain([DurableDispatchType::Step], 100);
    expect($first->claimed)->toBe(1)
        ->and(eagerOutboxRow($direct))->not->toBeNull()
        ->and(eagerOutboxRow($relayOnly))->toBeNull();

    // drain() reclaims strictly after reservation_timeout_seconds (60).
    $this->travel(60)->seconds();
    expect($outbox->drain([DurableDispatchType::Step], 100)->claimed)->toBe(0);

    // swarm:health --durable counts a reserved row as pending only after 2x the timeout.
    $this->travel(59)->seconds();
    expect(eagerHealthRelayLine())->toContain('no pending rows');

    $this->travel(2)->seconds();
    expect(eagerHealthRelayLine())->toContain('1 row(s) aging past 120s');

    $reclaim = $outbox->drain([DurableDispatchType::Step], 100);
    expect($reclaim->claimed)->toBe(1)
        ->and($reclaim->reclaimed)->toBe(1)
        ->and($reclaim->dispatched)->toBe(1)
        ->and(eagerOutboxRow($direct))->toBeNull();
});

test('settle deletes exactly the given row and is contained when the delete fails', function (): void {
    Exceptions::fake();
    $outbox = app(DurableOutbox::class);
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $mine = $outbox->enqueueStep($runId, 1, null, null, directDispatch: true);
    $sibling = $outbox->enqueueStep($runId, 1, null, null, directDispatch: true);

    $outbox->settle($mine);
    $outbox->settle($mine);

    expect(eagerOutboxRow($mine))->toBeNull()
        ->and(eagerOutboxRow($sibling))->not->toBeNull();

    config()->set('swarm.tables.durable_outbox', 'missing_swarm_durable_outbox');
    expect(fn () => app(DatabaseDurableOutbox::class)->settle($sibling))->not->toThrow(Exception::class);
    Exceptions::assertReported(QueryException::class);
});

// ---------------------------------------------------------------------------
// Flag off: today's outbox-only behaviour, pinned
// ---------------------------------------------------------------------------

test('flag off: a step advance pushes no job directly and leaves an unreserved row for the relay', function (): void {
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;

    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));

    $stepOneJobs = array_filter(eagerPushedAdvanceJobs(), fn (object $job): bool => $job instanceof AdvanceDurableSwarm && $job->stepIndex === 1);
    $rows = DB::table('swarm_durable_outbox')->where('run_id', $runId)->get();

    expect($stepOneJobs)->toBe([])
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->reserved_at)->toBeNull()
        ->and(app(DurableOutbox::class)->drain([DurableDispatchType::Step], 100)->dispatched)->toBe(1);
});

test('flag off: wait and child boundaries keep their step row for the relay, unreserved', function (string $swarm): void {
    Queue::fake();
    FakeResearcher::fake(['parent-step', 'child-research']);
    $runId = $swarm::make()->dispatchDurable('task')->runId;

    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));

    $rows = DB::table('swarm_durable_outbox')->where('run_id', $runId)->get();

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('waiting')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->reserved_at)->toBeNull()
        ->and(eagerDirectJobs())->toBe([]);
})->with([
    'declared wait' => [DurableWaitAttributeSwarm::class],
    'child swarm' => [ChildDispatchingSwarm::class],
]);

// ---------------------------------------------------------------------------
// Flag on: push on commit, settle on lease
// ---------------------------------------------------------------------------

test('flag on: a step advance pushes the next step carrying its born-reserved row', function (): void {
    eagerOn();
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;

    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));

    $jobs = eagerDirectStepJobs($runId);

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->stepIndex)->toBe(1)
        ->and($jobs[0]->afterCommit)->toBeFalse()
        ->and(eagerOutboxRow($jobs[0]->outboxEntryId)->reserved_at)->not->toBeNull()
        ->and(app(DurableOutbox::class)->drain([], 100)->claimed)->toBe(0);
});

test('the pushed job settles its own row at lease acquire, before the agent runs, and nothing else', function (): void {
    eagerOn();
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $job = eagerDirectStepJobs($runId)[0];
    $sibling = app(DurableOutbox::class)->enqueueStep($runId, 1, null, null);

    $rowPresentDuringExecution = null;
    FakeWriter::fake(function () use ($job, &$rowPresentDuringExecution): string {
        $rowPresentDuringExecution = eagerOutboxRow($job->outboxEntryId) !== null;

        return 'writer-out';
    });

    $job->handle($manager);

    expect($rowPresentDuringExecution)->toBeFalse()
        ->and(eagerOutboxRow($job->outboxEntryId))->toBeNull()
        ->and(eagerOutboxRow($sibling))->not->toBeNull();
});

test('a job whose lease is rejected does not settle its row', function (): void {
    eagerOn();
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    // The run is at step 1; a direct job for step 0 can no longer acquire the lease.
    $stale = app(DurableOutbox::class)->enqueueStep($runId, 0, null, null, directDispatch: true);
    (new AdvanceDurableSwarm($runId, 0, outboxEntryId: $stale))->handle($manager);

    expect(eagerOutboxRow($stale))->not->toBeNull();
});

test('a pushed branch job settles its own row at lease acquire, before the agent runs', function (): void {
    eagerOn();
    Queue::fake();
    $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    $jobs = eagerDirectBranchJobs($runId);
    $first = array_values(array_filter($jobs, fn (AdvanceDurableBranch $job): bool => $job->branchId === 'parallel:0'))[0];

    $rowPresentDuringExecution = null;
    FakeResearcher::fake(function () use ($first, &$rowPresentDuringExecution): string {
        $rowPresentDuringExecution = eagerOutboxRow($first->outboxEntryId) !== null;

        return 'research-out';
    });

    $first->handle($manager);

    expect($jobs)->toHaveCount(3)
        ->and($rowPresentDuringExecution)->toBeFalse()
        ->and(eagerOutboxRow($first->outboxEntryId))->toBeNull();

    foreach ($jobs as $other) {
        if ($other !== $first) {
            expect(eagerOutboxRow($other->outboxEntryId))->not->toBeNull();
        }
    }
});

test('a lost push is delivered by the relay once the reservation goes stale', function (): void {
    Exceptions::fake();
    eagerOn();
    eagerRegisterRecordingQueue('eager-lost', afterCommit: false);
    config()->set('swarm.durable.queue.connection', 'eager-lost');
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);

    // The process dies between commit and push: the push never reaches the queue.
    EagerRecordingQueue::$throw = true;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    EagerRecordingQueue::$throw = false;
    EagerRecordingQueue::$pushed = [];

    Exceptions::assertReported(RuntimeException::class);
    expect(eagerOutboxCount($runId))->toBe(1)
        ->and(app(DurableOutbox::class)->drain([], 100)->claimed)->toBe(0);

    $this->travel(61)->seconds();
    $result = app(DurableOutbox::class)->drain([], 100);

    expect($result->reclaimed)->toBe(1)
        ->and($result->dispatched)->toBe(1)
        ->and(EagerRecordingQueue::$pushed)->toHaveCount(1);

    $relayJob = EagerRecordingQueue::$pushed[0];
    $relayJob->handle($manager);

    FakeWriter::assertPrompted('research-out');
    expect($relayJob->outboxEntryId)->toBeNull()
        ->and($manager->find($runId)['next_step_index'])->toBe(2);
});

test('an eager push plus a relay push for the same step advance it exactly once', function (): void {
    eagerOn();
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $eager = eagerDirectStepJobs($runId)[0];

    // The eager job is slow to start: the reservation goes stale and the relay
    // pushes its own job before either has run.
    $this->travel(61)->seconds();
    $drain = app(DurableOutbox::class)->drain([], 100);
    $relay = array_values(array_filter(
        eagerPushedAdvanceJobs(),
        fn (object $job): bool => $job instanceof AdvanceDurableSwarm && $job->stepIndex === 1 && $job->outboxEntryId === null,
    ));

    expect($drain->reclaimed)->toBe(1)
        ->and($relay)->toHaveCount(1);

    $runs = 0;
    FakeWriter::fake(function () use (&$runs): string {
        $runs++;

        return 'writer-out';
    });

    $eager->handle($manager);
    $relay[0]->handle($manager);

    expect($runs)->toBe(1)
        ->and(app(SwarmHistory::class)->find($runId)['steps'])->toHaveCount(2)
        ->and($manager->find($runId)['next_step_index'])->toBe(2);
});

// ---------------------------------------------------------------------------
// The boundary ordering
// ---------------------------------------------------------------------------

test('flag on: entering a declared wait pushes nothing and settles the dead step row; the signal pushes afresh', function (): void {
    eagerOn();
    Queue::fake();
    $response = DurableWaitAttributeSwarm::make()->dispatchDurable('wait-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect($manager->find($response->runId)['status'])->toBe('waiting')
        ->and(eagerDirectJobs($response->runId))->toBe([])
        ->and(eagerOutboxCount($response->runId))->toBe(0);

    $response->signal('approval_received', ['approved' => true], 'eager-signal');

    $jobs = eagerDirectStepJobs($response->runId);
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->stepIndex)->toBe(1)
        ->and(eagerOutboxRow($jobs[0]->outboxEntryId)->reserved_at)->not->toBeNull();

    $jobs[0]->handle($manager);

    expect($manager->find($response->runId)['status'])->toBe('completed')
        ->and(eagerOutboxCount($response->runId))->toBe(0);
});

test('flag on: no parent step job is pushed before the child-swarm boundary is entered', function (): void {
    eagerOn();
    Queue::fake();
    FakeResearcher::fake(['parent-step', 'child-research']);
    $response = ChildDispatchingSwarm::make()->dispatchDurable('parent-task');
    $manager = app(DurableSwarmManager::class);

    $pushedAtBoundary = null;
    $manager->afterChildIntentForTesting(function () use ($response, &$pushedAtBoundary): void {
        $pushedAtBoundary = count(eagerDirectStepJobs($response->runId));
    });

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect($pushedAtBoundary)->toBe(0)
        ->and(eagerDirectStepJobs($response->runId))->toBe([])
        ->and($manager->find($response->runId)['status'])->toBe('waiting')
        ->and(eagerOutboxCount($response->runId))->toBe(0);
});

// ---------------------------------------------------------------------------
// Every enqueue site pushes eagerly
// ---------------------------------------------------------------------------

test('flag on: top-level parallel fan-out pushes every branch with its row', function (): void {
    eagerOn();
    Queue::fake();
    $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-task')->runId;

    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));

    $jobs = eagerDirectBranchJobs($runId);
    expect(array_map(fn (AdvanceDurableBranch $job): string => $job->branchId, $jobs))->toBe(['parallel:0', 'parallel:1', 'parallel:2']);

    foreach ($jobs as $job) {
        expect(eagerOutboxRow($job->outboxEntryId)->reserved_at)->not->toBeNull();
    }
});

test('flag on: hierarchical branch-wait pushes its branches, and the join pushes the next step', function (): void {
    eagerOn();
    Queue::fake();
    FakeHierarchicalCoordinator::fake([[
        'start_at' => 'parallel_node',
        'nodes' => [
            'parallel_node' => ['type' => 'parallel', 'branches' => ['writer_node', 'editor_node'], 'next' => 'finish_node'],
            'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
            'editor_node' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor-branch'],
            'finish_node' => ['type' => 'finish', 'output_from' => 'editor_node'],
        ],
    ]]);
    $runId = FakeHierarchicalFullSwarm::make()->dispatchDurable('hierarchical-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    eagerDirectStepJobs($runId)[0]->handle($manager);

    $branches = eagerDirectBranchJobs($runId);
    expect(array_map(fn (AdvanceDurableBranch $job): string => $job->branchId, $branches))
        ->toBe(['parallel_node:writer_node', 'parallel_node:editor_node']);

    foreach ($branches as $branch) {
        $branch->handle($manager);
    }

    $join = array_values(array_filter(eagerDirectStepJobs($runId), fn (AdvanceDurableSwarm $job): bool => $job->stepIndex === 3));
    expect($join)->toHaveCount(1)
        ->and(eagerOutboxRow($join[0]->outboxEntryId)->reserved_at)->not->toBeNull();

    $join[0]->handle($manager);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and(eagerOutboxCount($runId))->toBe(0);
});

test('flag on: resuming a waiting run re-pushes its recoverable branches', function (): void {
    eagerOn();
    Queue::fake();
    $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $before = count(eagerDirectBranchJobs($runId));

    $manager->pause($runId);
    $manager->resume($runId);

    $redispatched = array_slice(eagerDirectBranchJobs($runId), $before);
    expect($redispatched)->not->toBe([]);

    foreach ($redispatched as $job) {
        expect(eagerOutboxRow($job->outboxEntryId)->reserved_at)->not->toBeNull();
    }
});

test('flag on: resuming a paused pending run pushes its step', function (): void {
    eagerOn();
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);

    $manager->pause($runId);
    $manager->resume($runId);

    $jobs = eagerDirectStepJobs($runId);
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->stepIndex)->toBe(0)
        ->and(eagerOutboxRow($jobs[0]->outboxEntryId)->reserved_at)->not->toBeNull();
});

test('flag on: a zero-delay step retry pushes the retried step', function (): void {
    eagerOn();
    Queue::fake();
    FlakyDurableAgent::reset(failuresBeforeSuccess: 1);
    $runId = ZeroDelayRetryDurableSwarm::make()->dispatchDurable('retry-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    $jobs = eagerDirectStepJobs($runId);
    expect(FlakyDurableAgent::$attempts)->toBe(1)
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0]->stepIndex)->toBe(0);

    $jobs[0]->handle($manager);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and(FlakyDurableAgent::$attempts)->toBe(2);
});

test('flag on: a zero-delay branch retry pushes the retried branch', function (): void {
    eagerOn();
    Queue::fake();
    FlakyDurableAgent::reset(failuresBeforeSuccess: 1);
    FakeResearcher::fake(['stable-branch']);
    $runId = ZeroDelayRetryParallelDurableSwarm::make()->dispatchDurable('parallel-retry-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $flaky = array_values(array_filter(eagerDirectBranchJobs($runId), fn (AdvanceDurableBranch $job): bool => $job->branchId === 'parallel:1'))[0];
    $flaky->handle($manager);

    $retries = array_values(array_filter(
        eagerDirectBranchJobs($runId),
        fn (AdvanceDurableBranch $job): bool => $job->branchId === 'parallel:1' && $job !== $flaky,
    ));

    expect($retries)->toHaveCount(1)
        ->and(eagerOutboxRow($retries[0]->outboxEntryId)->reserved_at)->not->toBeNull();
});

test('flag on: a finished child swarm pushes the parent continuation', function (): void {
    eagerOn();
    Queue::fake();
    FakeResearcher::fake(['parent-step', 'child-research']);
    FakeWriter::fake(['parent-final', 'child-writer']);
    FakeEditor::fake(['child-output']);
    $response = ChildDispatchingSwarm::make()->dispatchDurable('parent-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    $childRunId = $manager->inspect($response->runId)->children[0]['child_run_id'];

    (new AdvanceDurableSwarm($childRunId, 0))->handle($manager);
    (new AdvanceDurableSwarm($childRunId, 1))->handle($manager);
    (new AdvanceDurableSwarm($childRunId, 2))->handle($manager);

    $parent = eagerDirectStepJobs($response->runId);
    expect($manager->find($response->runId)['status'])->toBe('pending')
        ->and($parent)->toHaveCount(1)
        ->and($parent[0]->stepIndex)->toBe(1);

    $parent[0]->handle($manager);

    expect($manager->find($response->runId)['status'])->toBe('completed')
        ->and(eagerOutboxCount($response->runId))->toBe(0);
});

test('flag on: queue() multi_worker hierarchical branches are pushed eagerly', function (): void {
    eagerOn();
    Queue::fake();
    config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');
    FakeHierarchicalCoordinator::fake([[
        'start_at' => 'parallel_node',
        'nodes' => [
            'parallel_node' => ['type' => 'parallel', 'branches' => ['writer_node', 'editor_node'], 'next' => 'finish_node'],
            'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
            'editor_node' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor-branch'],
            'finish_node' => ['type' => 'finish', 'output_from' => 'editor_node'],
        ],
    ]]);
    $context = RunContext::from('queued-hierarchical-task', 'eager-qhpc-1');

    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    $branches = eagerDirectBranchJobs('eager-qhpc-1');
    expect($branches)->toHaveCount(2);

    foreach ($branches as $branch) {
        expect(eagerOutboxRow($branch->outboxEntryId)->reserved_at)->not->toBeNull();
        $branch->handle(app(DurableSwarmManager::class));
        expect(eagerOutboxRow($branch->outboxEntryId))->toBeNull();
    }
});

test('consecutive runs through one manager each push only their own jobs', function (): void {
    eagerOn();
    Queue::fake();
    $manager = app(DurableSwarmManager::class);
    $first = FakeSequentialSwarm::make()->dispatchDurable('first')->runId;
    $second = FakeSequentialSwarm::make()->dispatchDurable('second')->runId;

    (new AdvanceDurableSwarm($first, 0))->handle($manager);
    (new AdvanceDurableSwarm($second, 0))->handle($manager);

    $firstJobs = eagerDirectStepJobs($first);
    $secondJobs = eagerDirectStepJobs($second);

    expect($firstJobs)->toHaveCount(1)
        ->and($secondJobs)->toHaveCount(1)
        ->and(eagerOutboxRow($firstJobs[0]->outboxEntryId)->run_id)->toBe($first)
        ->and(eagerOutboxRow($secondJobs[0]->outboxEntryId)->run_id)->toBe($second);
});

// ---------------------------------------------------------------------------
// The flag decision is captured once per operation
// ---------------------------------------------------------------------------

test('the push follows the decision captured when the row was written, not the live flag', function (): void {
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $eager = eagerDispatcher(app('db.transactions'));

    // Written direct, flag later switched off: the born-reserved row is still pushed.
    eagerOn();
    DB::transaction(function () use ($eager, $runId): void {
        $id = app(DurableOutbox::class)->enqueueStep($runId, 1, null, null, directDispatch: $eager->enabled());
        config()->set('swarm.durable.eager_dispatch', false);
        $eager->step($id, $runId, 1, null, null);
    });

    expect(eagerDirectStepJobs($runId))->toHaveCount(1);
});

test('a queued job carrying an entry still settles after the flag is switched off', function (): void {
    eagerOn();
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $job = eagerDirectStepJobs($runId)[0];

    config()->set('swarm.durable.eager_dispatch', false);
    $job->handle($manager);

    expect(eagerOutboxRow($job->outboxEntryId))->toBeNull();
});

// ---------------------------------------------------------------------------
// Commit ownership: the push waits for the SWARM connection's commit
// ---------------------------------------------------------------------------

test('nested swarm transactions push only when the outermost commits', function (): void {
    Queue::fake();
    $eager = eagerDispatcher(app('db.transactions'));

    DB::transaction(function () use ($eager): void {
        DB::transaction(fn () => $eager->step(11, 'run-nested', 1, null, null));
        expect(eagerDirectJobs())->toBe([]);
    });

    expect(eagerDirectJobs())->toHaveCount(1);
});

test("another connection's commit inside the swarm transaction does not fire the push", function (): void {
    Queue::fake();
    $eager = eagerDispatcher(app('db.transactions'));
    $other = DB::connection('eager-other');

    DB::beginTransaction();
    $other->beginTransaction();
    $eager->step(12, 'run-cross', 1, null, null);
    $other->commit();

    expect(eagerDirectJobs())->toBe([]);

    DB::commit();

    expect(eagerDirectJobs())->toHaveCount(1);
});

test('a swarm rollback drops the push even when another connection committed', function (): void {
    Queue::fake();
    $eager = eagerDispatcher(app('db.transactions'));
    $other = DB::connection('eager-other');

    DB::beginTransaction();
    $other->beginTransaction();
    $eager->step(13, 'run-rollback', 1, null, null);
    $other->commit();
    DB::rollBack();

    expect(eagerDirectJobs())->toBe([]);
});

test('with the swarm work committed, the push is not held hostage by an open transaction elsewhere', function (): void {
    Queue::fake();
    $eager = eagerDispatcher(app('db.transactions'));
    $other = DB::connection('eager-other');

    $other->beginTransaction();
    $eager->step(14, 'run-outer-other', 1, null, null);

    expect(eagerDirectJobs())->toHaveCount(1);

    $other->rollBack();

    expect(eagerDirectJobs())->toHaveCount(1);
});

test('rolling back the savepoint that registered the push drops it although the outer transaction commits', function (): void {
    Queue::fake();
    $eager = eagerDispatcher(app('db.transactions'));

    DB::beginTransaction();
    DB::beginTransaction();
    $eager->step(15, 'run-savepoint', 1, null, null);
    DB::rollBack();
    DB::commit();

    expect(eagerDirectJobs())->toBe([]);
});

test('a committed savepoint inside a rolled-back savepoint drops the push', function (): void {
    Queue::fake();
    $eager = eagerDispatcher(app('db.transactions'));

    DB::beginTransaction();
    DB::beginTransaction();
    DB::beginTransaction();
    $eager->step(16, 'run-descendant', 1, null, null);
    DB::commit();
    DB::rollBack();
    DB::commit();

    expect(eagerDirectJobs())->toBe([]);
});

test("under the testing transaction manager the wrapper transaction is excluded, as Laravel's convention", function (): void {
    Queue::fake();
    $connection = DB::connection();
    $original = app('db.transactions');
    $testing = new TestingTransactionsManager([$connection->getName()]);
    $connection->setTransactionManager($testing);

    try {
        $eager = eagerDispatcher($testing);
        $connection->beginTransaction(); // the RefreshDatabase-style wrapper
        $connection->beginTransaction(); // the application's transaction
        $eager->step(17, 'run-testing-manager', 1, null, null);

        expect(eagerDirectJobs())->toBe([]);

        $connection->commit();

        expect(eagerDirectJobs())->toHaveCount(1);

        $connection->rollBack();
    } finally {
        $connection->setTransactionManager($original);
    }
});

test('without a transactions manager the push is skipped inside a transaction and the relay recovers the row', function (): void {
    Queue::fake();
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $eager = eagerDispatcher(null);

    DB::transaction(function () use ($eager, $runId): void {
        $id = app(DurableOutbox::class)->enqueueStep($runId, 1, null, null, directDispatch: true);
        $eager->step($id, $runId, 1, null, null);
    });

    expect(eagerDirectJobs())->toBe([]);

    $this->travel(61)->seconds();
    expect(app(DurableOutbox::class)->drain([], 100)->reclaimed)->toBe(1);
});

// ---------------------------------------------------------------------------
// The queue cannot defer the push a second time
// ---------------------------------------------------------------------------

test('native jobs and after_commit queue connections push at the swarm commit, not at a later foreign commit', function (int $version, string $stepClass, string $branchClass, bool $connectionAfterCommit): void {
    eagerRegisterRecordingQueue('eager-recording', afterCommit: $connectionAfterCommit);
    $eager = eagerDispatcher(app('db.transactions'), $version === 0 ? null : eagerNativeJobDispatcher($version));
    $other = DB::connection('eager-other');

    $other->beginTransaction();
    $eager->step(21, 'run-native', 1, 'eager-recording', null);
    $eager->branch(22, 'run-native', 'parallel:0', 'eager-recording', null);
    $other->rollBack();

    expect(EagerRecordingQueue::$pushed)->toHaveCount(2)
        ->and(EagerRecordingQueue::$pushed[0])->toBeInstanceOf($stepClass)
        ->and(EagerRecordingQueue::$pushed[0]->outboxEntryId)->toBe(21)
        ->and(EagerRecordingQueue::$pushed[1])->toBeInstanceOf($branchClass)
        ->and(EagerRecordingQueue::$pushed[1]->outboxEntryId)->toBe(22);
})->with([
    'native input' => [NativeInputManifest::VERSION, AdvanceNativeInputDurableSwarm::class, AdvanceNativeInputDurableBranch::class, false],
    'native agent settings' => [NativeInputManifest::SETTINGS_VERSION, AdvanceNativeAgentSettingsDurableSwarm::class, AdvanceNativeAgentSettingsDurableBranch::class, false],
    'base job on an after_commit connection' => [0, AdvanceDurableSwarm::class, AdvanceDurableBranch::class, true],
]);

test('a push that throws at the queue is reported and contained', function (): void {
    Exceptions::fake();
    eagerRegisterRecordingQueue('eager-recording', afterCommit: true);
    EagerRecordingQueue::$throw = true;
    $eager = eagerDispatcher(app('db.transactions'));

    expect(fn () => DB::transaction(fn () => $eager->step(23, 'run-throw', 1, 'eager-recording', null)))
        ->not->toThrow(Exception::class);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'queue unavailable');
});

// ---------------------------------------------------------------------------
// The entry id on the wire
// ---------------------------------------------------------------------------

test('every advance variant carries the entry id through serialization and into the manager', function (string $class, bool $isBranch): void {
    $job = $isBranch ? new $class('run-wire', 'parallel:0', outboxEntryId: 9) : new $class('run-wire', 3, outboxEntryId: 9);
    $restored = unserialize(serialize($job));

    $manager = Mockery::mock(DurableSwarmManager::class);
    $isBranch
        ? $manager->shouldReceive('advanceBranch')->once()->with('run-wire', 'parallel:0', 9)
        : $manager->shouldReceive('advance')->once()->with('run-wire', 3, 9);

    expect($restored->outboxEntryId)->toBe(9);

    $restored->handle($manager);
})->with([
    'step' => [AdvanceDurableSwarm::class, false],
    'native input step' => [AdvanceNativeInputDurableSwarm::class, false],
    'native settings step' => [AdvanceNativeAgentSettingsDurableSwarm::class, false],
    'branch' => [AdvanceDurableBranch::class, true],
    'native input branch' => [AdvanceNativeInputDurableBranch::class, true],
    'native settings branch' => [AdvanceNativeAgentSettingsDurableBranch::class, true],
]);

test('a payload without the entry id restores it as null and a payload with an unknown key ignores it', function (string $class, bool $isBranch): void {
    $job = $isBranch ? new $class('run-wire', 'parallel:0', outboxEntryId: 9) : new $class('run-wire', 3, outboxEntryId: 9);

    $withoutEntry = eagerReserialize($job, dropEntryId: true, addUnknown: false);
    $withUnknown = eagerReserialize($job, dropEntryId: false, addUnknown: true);

    expect($withoutEntry->outboxEntryId)->toBeNull()
        ->and($withUnknown->outboxEntryId)->toBe(9)
        ->and(array_key_exists('unknownFuture', get_object_vars($withUnknown)))->toBeFalse();
})->with([
    'step' => [AdvanceDurableSwarm::class, false],
    'native input step' => [AdvanceNativeInputDurableSwarm::class, false],
    'native settings step' => [AdvanceNativeAgentSettingsDurableSwarm::class, false],
    'branch' => [AdvanceDurableBranch::class, true],
    'native input branch' => [AdvanceNativeInputDurableBranch::class, true],
    'native settings branch' => [AdvanceNativeAgentSettingsDurableBranch::class, true],
]);

test('a job queued without an entry id advances normally and settles nothing', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $unrelated = app(DurableOutbox::class)->enqueueStep($runId, 0, null, null, directDispatch: true);
    $job = eagerReserialize(new AdvanceDurableSwarm($runId, 0, outboxEntryId: 1), dropEntryId: true, addUnknown: false);

    $job->handle(app(DurableSwarmManager::class));

    expect(app(DurableSwarmManager::class)->find($runId)['next_step_index'])->toBe(1)
        ->and(eagerOutboxRow($unrelated))->not->toBeNull();
});
