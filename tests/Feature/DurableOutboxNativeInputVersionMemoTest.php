<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeAgentSettingsDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeAgentSettingsDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeNativeAgentSettingsQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeNativeInputQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableOutbox;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * A context store and a native-input store that serve canned rows, count every
 * find() and can be told to throw on the next find() of a given id.
 */
final class MemoCountingStores
{
    /** @var array<string, array<string, mixed>> */
    public array $contexts = [];

    /** @var array<string, array<string, mixed>> */
    public array $nativeInputs = [];

    /** @var array<string, int> */
    public array $contextReads = [];

    /** @var array<string, int> */
    public array $nativeInputReads = [];

    /** @var array<string, int> */
    public array $contextFailures = [];

    /** @var array<string, int> */
    public array $nativeInputFailures = [];

    public function contextStore(): ContextStore
    {
        return new class($this) implements ContextStore
        {
            public function __construct(private readonly MemoCountingStores $stores) {}

            public function put(RunContext $context, int $ttlSeconds): void {}

            public function find(string $runId): ?array
            {
                $this->stores->contextReads[$runId] = ($this->stores->contextReads[$runId] ?? 0) + 1;

                if (($this->stores->contextFailures[$runId] ?? 0) > 0) {
                    $this->stores->contextFailures[$runId]--;

                    throw new RuntimeException("Context store unavailable for [{$runId}].");
                }

                return $this->stores->contexts[$runId] ?? null;
            }
        };
    }

    public function nativeInputStore(): NativeInputStore
    {
        return new class($this) implements NativeInputStore
        {
            public function __construct(private readonly MemoCountingStores $stores) {}

            public function put(string $id, string $runId, array $payload, string $hash, int $expiresAt): void {}

            public function find(string $id): ?array
            {
                $this->stores->nativeInputReads[$id] = ($this->stores->nativeInputReads[$id] ?? 0) + 1;

                if (($this->stores->nativeInputFailures[$id] ?? 0) > 0) {
                    $this->stores->nativeInputFailures[$id]--;

                    throw new RuntimeException("Native input store unavailable for [{$id}].");
                }

                return $this->stores->nativeInputs[$id] ?? null;
            }

            public function activate(string $id, string $runId): void {}

            public function revoke(string $id, string $runId): void {}
        };
    }

    public function totalContextReads(): int
    {
        return array_sum($this->contextReads);
    }

    public function totalNativeInputReads(): int
    {
        return array_sum($this->nativeInputReads);
    }
}

/**
 * Four runs, one per version path: no native input, native input v1, native
 * agent settings v2, and no context row at all.
 */
function memoStoresWithFourRuns(): MemoCountingStores
{
    $stores = new MemoCountingStores;
    $stores->contexts['run-base'] = ['run_id' => 'run-base'];
    $stores->contexts['run-native'] = ['run_id' => 'run-native', 'native_input_ref' => 'ref-native'];
    $stores->contexts['run-settings'] = ['run_id' => 'run-settings', 'native_input_ref' => 'ref-settings'];
    $stores->nativeInputs['ref-native'] = ['format_version' => 1];
    $stores->nativeInputs['ref-settings'] = ['format_version' => 2];

    return $stores;
}

function memoDispatcher(MemoCountingStores $stores): DurableJobDispatcher
{
    return new DurableJobDispatcher(app(Repository::class), $stores->contextStore(), $stores->nativeInputStore());
}

function memoOutbox(DurableJobDispatcher $dispatcher): DatabaseDurableOutbox
{
    return new DatabaseDurableOutbox(DB::connection(), app(Repository::class), $dispatcher);
}

/**
 * Enqueue a step, a branch and a queued resume for each run, twice over, so every
 * run appears in six entries and every lane carries every version path.
 *
 * @param  list<string>  $runIds
 */
function memoEnqueueEveryLane(DatabaseDurableOutbox $outbox, array $runIds): void
{
    foreach ([0, 1] as $round) {
        foreach ($runIds as $runId) {
            $outbox->enqueueStep($runId, $round, null, null);
            $outbox->enqueueBranch($runId, "branch-{$round}", null, null);
            $outbox->enqueueQueuedResume($runId, null, null);
        }
    }
}

/**
 * Every pushed job as "class|run|step-or-branch", sorted, so a repeated entry
 * counts once per push.
 *
 * @return list<string>
 */
function memoPushedJobs(): array
{
    $pushed = [];

    foreach (Queue::pushedJobs() as $class => $jobs) {
        foreach ($jobs as $push) {
            $job = $push['job'];
            $identity = match (true) {
                $job instanceof AdvanceDurableSwarm => (string) $job->stepIndex,
                $job instanceof AdvanceDurableBranch => $job->branchId,
                default => '-',
            };
            $pushed[] = "{$class}|{$job->runId}|{$identity}";
        }
    }

    sort($pushed);

    return $pushed;
}

/**
 * @param  array<string, array{step: class-string, branch: class-string, resume: class-string}>  $variants
 * @return list<string>
 */
function memoExpectedJobs(array $variants): array
{
    $expected = [];

    foreach ($variants as $runId => $classes) {
        foreach ([0, 1] as $round) {
            $expected[] = "{$classes['step']}|{$runId}|{$round}";
            $expected[] = "{$classes['branch']}|{$runId}|branch-{$round}";
            $expected[] = "{$classes['resume']}|{$runId}|-";
        }
    }

    sort($expected);

    return $expected;
}

/**
 * @return array{step: class-string, branch: class-string, resume: class-string}
 */
function memoBaseVariants(): array
{
    return ['step' => AdvanceDurableSwarm::class, 'branch' => AdvanceDurableBranch::class, 'resume' => ResumeQueuedHierarchicalSwarm::class];
}

/**
 * @return array{step: class-string, branch: class-string, resume: class-string}
 */
function memoNativeVariants(): array
{
    return ['step' => AdvanceNativeInputDurableSwarm::class, 'branch' => AdvanceNativeInputDurableBranch::class, 'resume' => ResumeNativeInputQueuedHierarchicalSwarm::class];
}

/**
 * @return array{step: class-string, branch: class-string, resume: class-string}
 */
function memoSettingsVariants(): array
{
    return ['step' => AdvanceNativeAgentSettingsDurableSwarm::class, 'branch' => AdvanceNativeAgentSettingsDurableBranch::class, 'resume' => ResumeNativeAgentSettingsQueuedHierarchicalSwarm::class];
}

beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    Queue::fake();

    // Outbox rows reference their durable run; the context stores themselves are fakes.
    $now = now('UTC');
    foreach (['run-base', 'run-native', 'run-settings', 'run-missing'] as $runId) {
        DB::table('swarm_durable_runs')->insert([
            'run_id' => $runId,
            'swarm_class' => 'ExampleSwarm',
            'topology' => 'sequential',
            'status' => 'running',
            'next_step_index' => 0,
            'total_steps' => 2,
            'timeout_at' => $now->copy()->addHour(),
            'step_timeout_seconds' => 300,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
});

test('a drain reads each run context once, not once per entry, and still picks every job variant', function (): void {
    $stores = memoStoresWithFourRuns();
    $outbox = memoOutbox(memoDispatcher($stores));
    memoEnqueueEveryLane($outbox, ['run-base', 'run-native', 'run-settings', 'run-missing']);

    $result = $outbox->drain([], 100);

    // 24 entries across 4 runs: 4 context reads, and one native-input read per run with a ref.
    expect($result->dispatched)->toBe(24)
        ->and($result->failed)->toBe(0)
        ->and($stores->contextReads)->toBe(['run-base' => 1, 'run-native' => 1, 'run-settings' => 1, 'run-missing' => 1])
        ->and($stores->nativeInputReads)->toBe(['ref-native' => 1, 'ref-settings' => 1])
        ->and(memoPushedJobs())->toBe(memoExpectedJobs([
            'run-base' => memoBaseVariants(),
            'run-native' => memoNativeVariants(),
            'run-settings' => memoSettingsVariants(),
            // No context row: the null version path, exactly as before — the base jobs.
            'run-missing' => memoBaseVariants(),
        ]));
});

test('the memo does not outlive a drain: the next drain reads again and sees a changed native input', function (): void {
    $stores = memoStoresWithFourRuns();
    $dispatcher = memoDispatcher($stores);
    $outbox = memoOutbox($dispatcher);

    memoEnqueueEveryLane($outbox, ['run-base', 'run-native']);
    $outbox->drain([], 100);

    expect($stores->totalContextReads())->toBe(2)
        ->and($stores->nativeInputReads)->toBe(['ref-native' => 1]);

    // The run moves to native agent settings between drains.
    $stores->contexts['run-native']['native_input_ref'] = 'ref-settings';
    Queue::fake();
    memoEnqueueEveryLane($outbox, ['run-native']);

    $outbox->drain([], 100);

    expect($stores->contextReads)->toBe(['run-base' => 1, 'run-native' => 2])
        ->and($stores->nativeInputReads)->toBe(['ref-native' => 1, 'ref-settings' => 1])
        ->and(memoPushedJobs())->toBe(memoExpectedJobs(['run-native' => memoSettingsVariants()]));

    // The dispatcher the outbox holds never memoizes: each job it builds reads again.
    $dispatcher->makeStepJob('run-base', 0);
    $dispatcher->makeStepJob('run-base', 1);

    expect($stores->contextReads['run-base'])->toBe(3);
});

test('two drain scopes taken from one dispatcher keep separate memos', function (): void {
    $stores = memoStoresWithFourRuns();
    $dispatcher = memoDispatcher($stores);

    $first = $dispatcher->forDrain();
    expect($first->makeStepJob('run-native', 0))->toBeInstanceOf(AdvanceNativeInputDurableSwarm::class);

    $stores->contexts['run-native']['native_input_ref'] = 'ref-settings';

    $second = $dispatcher->forDrain();
    expect($second->makeStepJob('run-native', 0))->toBeInstanceOf(AdvanceNativeAgentSettingsDurableSwarm::class);

    // Each scope keeps what it read, without reading again.
    expect(get_class($first->makeBranchJob('run-native', 'b')))->toBe(AdvanceNativeInputDurableBranch::class)
        ->and(get_class($first->makeQueuedResumeJob('run-native')))->toBe(ResumeNativeInputQueuedHierarchicalSwarm::class)
        ->and(get_class($second->makeBranchJob('run-native', 'b')))->toBe(AdvanceNativeAgentSettingsDurableBranch::class)
        ->and(get_class($second->makeQueuedResumeJob('run-native')))->toBe(ResumeNativeAgentSettingsQueuedHierarchicalSwarm::class)
        ->and($stores->contextReads['run-native'])->toBe(2);
});

test('a context read that fails is not memoized: the next entry of the run reads again', function (): void {
    $stores = memoStoresWithFourRuns();
    $stores->contextFailures['run-native'] = 1;
    $outbox = memoOutbox(memoDispatcher($stores));

    $outbox->enqueueStep('run-native', 0, null, null);
    $outbox->enqueueStep('run-native', 1, null, null);
    $outbox->enqueueQueuedResume('run-native', null, null);

    $result = $outbox->drain([], 100);

    expect($result->failed)->toBe(1)
        ->and($result->dispatched)->toBe(2)
        ->and($stores->contextReads['run-native'])->toBe(2)
        ->and($stores->nativeInputReads['ref-native'])->toBe(1)
        ->and(memoPushedJobs())->toBe([
            AdvanceNativeInputDurableSwarm::class.'|run-native|1',
            ResumeNativeInputQueuedHierarchicalSwarm::class.'|run-native|-',
        ]);
});

test('a native input read that fails is not memoized: the next entry of the run reads again', function (): void {
    $stores = memoStoresWithFourRuns();
    $stores->nativeInputFailures['ref-settings'] = 1;
    $outbox = memoOutbox(memoDispatcher($stores));

    $outbox->enqueueBranch('run-settings', 'b0', null, null);
    $outbox->enqueueBranch('run-settings', 'b1', null, null);
    $outbox->enqueueQueuedResume('run-settings', null, null);

    $result = $outbox->drain([], 100);

    expect($result->failed)->toBe(1)
        ->and($result->dispatched)->toBe(2)
        ->and($stores->contextReads['run-settings'])->toBe(2)
        ->and($stores->nativeInputReads['ref-settings'])->toBe(2)
        ->and(memoPushedJobs())->toBe([
            AdvanceNativeAgentSettingsDurableBranch::class.'|run-settings|b1',
            ResumeNativeAgentSettingsQueuedHierarchicalSwarm::class.'|run-settings|-',
        ]);
});
