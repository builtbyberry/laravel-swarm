<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\HierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\ParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\PendingAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\RejectSuccessGuardrail;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\SequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\StaticSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\StreamingParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\StreamingSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\StreamingStaticSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Psr\Log\LoggerInterface;

function nativeOutcomeRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.default', 'null');
    config()->set('queue.connections.native-outcome', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'native-outcome');
    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
}

function nativeOutcomeAssertWorkerFailure(object $job, string $exception): void
{
    config()->set('swarm.queue.tries', 5);
    config()->set('swarm.durable.job.tries', 5);
    config()->set('queue.connections.native-worker', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'test', 'retry_after' => 90]);
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    $queue = app('queue')->connection('native-worker');
    $queue->push($job);
    $queued = $queue->pop('test');
    expect($queued->maxTries())->toBe(5);
    expect(fn () => app('queue.worker')->process('native-worker', $queued, new WorkerOptions(maxTries: 5)))
        ->toThrow($exception);
    expect($queued->hasFailed())->toBeTrue()->and($queued->isReleased())->toBeFalse()
        ->and($queue->size('test'))->toBe(0)->and(PendingAgent::$calls)->toBe(1);
}

function nativeOutcomeParallelPlan(): array
{
    return ['start_at' => 'parallel', 'nodes' => [
        'parallel' => ['type' => 'parallel', 'branches' => ['pending', 'writer'], 'next' => 'finish'],
        'pending' => ['type' => 'worker', 'agent' => PendingAgent::class, 'prompt' => 'pending'],
        'writer' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer'],
        'finish' => ['type' => 'finish', 'output_from' => 'writer'],
    ]];
}

beforeEach(function () {
    PendingAgent::$calls = 0;
    PendingAgent::$afterApproval = 0;
    config()->set('swarm.guardrails.step', [RejectSuccessGuardrail::class]);
    FakeWriter::fake(['writer-output']);
    FakeHierarchicalCoordinator::fake([(new StaticSwarm)->plan()]);
    Event::fake([SwarmCompleted::class, SwarmStepCompleted::class]);
});

it('rejects pending prompt responses before affected step completion', function (string $swarm) {
    expect(fn () => $swarm::make()->prompt('task'))->toThrow(UnsupportedNativeApprovalException::class);
    Event::assertNotDispatched(SwarmCompleted::class);
    Event::assertNotDispatched(SwarmStepCompleted::class, fn ($event) => $event->agentClass === PendingAgent::class);
    if ($swarm !== ParallelSwarm::class) {
        FakeWriter::assertNeverPrompted();
    }
})->with([SequentialSwarm::class, ParallelSwarm::class, StaticSwarm::class, HierarchicalSwarm::class]);

it('rejects a pending coordinator before reading its route plan', function (string $mode) {
    config()->set('tests.native.pending_coordinator', true);
    app()->bind(FakeHierarchicalCoordinator::class, fn () => new class extends FakeHierarchicalCoordinator
    {
        public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
        {
            return PendingAgent::pending();
        }
    });
    expect(function () use ($mode) {
        $response = HierarchicalSwarm::make()->{$mode}('task');
        if ($mode === 'stream') {
            iterator_to_array($response);
        }
    })->toThrow(UnsupportedNativeApprovalException::class);
    expect(PendingAgent::$calls)->toBe(0);
    Event::assertNotDispatched(SwarmStepCompleted::class);
})->with(['prompt', 'stream']);

it('rejects pending parallel workers through both hierarchy callers', function (string $swarm, string $mode) {
    config()->set('tests.native.plan', nativeOutcomeParallelPlan());
    FakeHierarchicalCoordinator::fake([nativeOutcomeParallelPlan()]);
    expect(function () use ($swarm, $mode) {
        $response = $swarm::make()->{$mode}('task');
        if ($mode === 'stream') {
            iterator_to_array($response);
        }
    })->toThrow(UnsupportedNativeApprovalException::class);
    Event::assertNotDispatched(SwarmCompleted::class);
})->with([StaticSwarm::class, HierarchicalSwarm::class])->with(['prompt', 'stream']);

it('rejects native stream events and final callback outcomes before stream success', function (string $path, bool $finalOnly) {
    config()->set('tests.native.final_only', $finalOnly);
    $stream = match ($path) {
        'sequential' => app(SwarmRunner::class)->agent(new PendingAgent)->stream('task'),
        'fallback' => SequentialSwarm::make()->stream('task'),
        'static' => StaticSwarm::make()->stream('task'),
        'hierarchical' => HierarchicalSwarm::make()->stream('task'),
    };
    $types = [];
    expect(function () use ($stream, &$types) {
        foreach ($stream as $event) {
            $types[] = $event->type();
        }
    })->toThrow(UnsupportedNativeApprovalException::class);
    expect($types)->not->toContain('swarm_stream_end');
    expect(PendingAgent::$afterApproval)->toBe(0);
    FakeWriter::assertNeverPrompted();
    Event::assertNotDispatched(SwarmCompleted::class);
})->with(['sequential', 'fallback', 'static', 'hierarchical'])->with([false, true]);

it('terminalizes durable pending steps without application-policy retry', function (string $swarm, bool $finalOnly) {
    nativeOutcomeRuntime();
    config()->set('tests.native.final_only', $finalOnly);
    config()->set('swarm.streaming.integrity.enabled', true);
    $runId = $swarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    $index = 0;
    if ($swarm === StreamingStaticSwarm::class) {
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
        $index = 1;
    }
    $job = (new AdvanceDurableSwarm($runId, $index))->withFakeQueueInteractions();
    expect(fn () => $job->handle($manager))->toThrow(UnsupportedNativeApprovalException::class);
    $job->assertFailedWith(UnsupportedNativeApprovalException::class);
    $job->assertNotReleased();
    expect($manager->find($runId)['status'])->toBe('failed')
        ->and($manager->find($runId)['retry_attempt'])->toBe(0);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    expect(PendingAgent::$calls)->toBe(1);
    FakeWriter::assertNeverPrompted();
    foreach (['swarm_run_histories', 'swarm_run_steps', 'swarm_durable_runs', 'swarm_durable_branches'] as $table) {
        expect(DB::table($table)->get()->toJson())->not->toContain('approval-secret', 'argument-secret', 'reason-secret', 'provider-secret');
    }
})->with([SequentialSwarm::class, StreamingSequentialSwarm::class, StreamingStaticSwarm::class])->with([false, true]);

it('fails rejected branch jobs while preserving configured partial parents', function (string $swarm, string $policy, bool $finalOnly) {
    nativeOutcomeRuntime();
    config()->set('swarm.streaming.integrity.enabled', true);
    config()->set('swarm.durable.parallel.failure_policy', $policy);
    config()->set('tests.native.final_only', $finalOnly);
    $runId = $swarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $branches = app(DurableRunStore::class)->branchesFor($runId);
    foreach ($branches as $branch) {
        $job = (new AdvanceDurableBranch($runId, $branch['branch_id']))->withFakeQueueInteractions();
        if ($branch['agent_class'] === PendingAgent::class) {
            expect(fn () => $job->handle($manager))->toThrow(UnsupportedNativeApprovalException::class);
            $job->assertFailedWith(UnsupportedNativeApprovalException::class);
            $job->assertNotReleased();
        } else {
            $job->handle($manager);
        }
    }
    $rejected = collect(app(DurableRunStore::class)->branchesFor($runId))->firstWhere('agent_class', PendingAgent::class);
    expect($rejected['status'])->toBe('failed')->and($rejected['retry_attempt'])->toBe(0);
    (new AdvanceDurableSwarm($runId, (int) $manager->find($runId)['next_step_index']))->handle($manager);
    expect($manager->find($runId)['status'])->toBe($policy === 'partial_success' ? 'completed' : 'failed');
    expect(PendingAgent::$calls)->toBe(1);
})->with([ParallelSwarm::class, StreamingParallelSwarm::class])->with(['partial_success', 'fail_run', 'collect_failures'])->with([false, true]);

it('fails real queued and broadcast workflow handlers without releasing', function (string $kind) {
    nativeOutcomeRuntime();
    $context = RunContext::fromTask('task');
    $job = $kind === 'invoke'
        ? new InvokeSwarm(SequentialSwarm::class, $context->toQueuePayload())
        : new BroadcastSwarm(SequentialSwarm::class, $context->toQueuePayload(), ['test']);
    config()->set('swarm.queue.tries', 5);
    $job->withFakeQueueInteractions();
    expect(fn () => app()->call([$job, 'handle']))->toThrow(UnsupportedNativeApprovalException::class);
    $job->assertFailedWith(UnsupportedNativeApprovalException::class);
    $job->assertNotReleased();
    FakeWriter::assertNeverPrompted();
    Event::assertNotDispatched(SwarmCompleted::class);
    $rows = DB::table('swarm_run_histories')->get()->toJson();
    expect($rows)->not->toContain('approval-secret', 'argument-secret', 'reason-secret', 'provider-secret');
})->with(['invoke', 'broadcast']);

it('prevents Laravel workers from retrying either native approval failure with five tries', function (string $kind, bool $nativeThrows, bool $listenerThrows) {
    nativeOutcomeRuntime();
    config()->set('tests.native.throw', $nativeThrows);
    if ($listenerThrows) {
        app('events')->listen(SwarmFailed::class, fn () => throw new RuntimeException('failure listener unavailable'));
    }

    config()->set('swarm.queue.tries', 5);
    $context = RunContext::fromTask('task');
    $job = $kind === 'invoke'
        ? new InvokeSwarm(SequentialSwarm::class, $context->toQueuePayload())
        : new BroadcastSwarm(SequentialSwarm::class, $context->toQueuePayload(), ['test']);
    nativeOutcomeAssertWorkerFailure($job, $nativeThrows ? ApprovalNotResumableException::class : UnsupportedNativeApprovalException::class);
})->with(['invoke', 'broadcast'])->with([false, true])->with([false, true]);

it('fails a queued hierarchical resume at the pending worker after a real parallel join', function (bool $nativeThrows, bool $listenerThrows) {
    nativeOutcomeRuntime();
    config()->set('tests.native.throw', $nativeThrows);
    config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');
    $plan = nativeOutcomeParallelPlan();
    $plan['nodes']['parallel']['branches'] = ['writer', 'second_writer'];
    $plan['nodes']['parallel']['next'] = 'pending';
    $plan['nodes']['second_writer'] = ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'second'];
    $plan['nodes']['pending']['next'] = 'finish';
    FakeHierarchicalCoordinator::fake([$plan]);
    $context = RunContext::fromTask('task');
    (new InvokeSwarm(HierarchicalSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));
    $manager = app(DurableSwarmManager::class);
    foreach (app(DurableRunStore::class)->branchesFor($context->runId) as $branch) {
        (new AdvanceDurableBranch($context->runId, $branch['branch_id']))->handle($manager);
    }
    if ($listenerThrows) {
        app('events')->listen(SwarmFailed::class, fn () => throw new RuntimeException('resume failure listener unavailable'));
    }
    $resume = new ResumeQueuedHierarchicalSwarm($context->runId);
    nativeOutcomeAssertWorkerFailure($resume, $nativeThrows ? ApprovalNotResumableException::class : UnsupportedNativeApprovalException::class);
    expect(app(RunHistoryStore::class)->find($context->runId)['status'])->toBe('failed')
        ->and($manager->find($context->runId)['status'])->toBe('failed')
        ->and(PendingAgent::$calls)->toBe(1);
    Event::assertNotDispatched(SwarmCompleted::class);
})->with([false, true])->with([false, true]);

it('never retries a native pre-response approval exception in durable runs or branches', function (string $swarm) {
    nativeOutcomeRuntime();
    config()->set('tests.native.throw', true);
    $runId = $swarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    if ($swarm === ParallelSwarm::class) {
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
        $branch = collect(app(DurableRunStore::class)->branchesFor($runId))->firstWhere('agent_class', PendingAgent::class);
        $job = new AdvanceDurableBranch($runId, $branch['branch_id']);
    } else {
        $job = new AdvanceDurableSwarm($runId, 0);
    }
    $job->withFakeQueueInteractions();
    expect(fn () => $job->handle($manager))->toThrow(ApprovalNotResumableException::class);
    $job->assertFailedWith(ApprovalNotResumableException::class);
    $job->assertNotReleased();
    $row = isset($branch) ? app(DurableRunStore::class)->findBranch($runId, $branch['branch_id']) : $manager->find($runId);
    expect($row['status'])->toBe('failed')->and($row['retry_attempt'])->toBe(0);
})->with([SequentialSwarm::class, ParallelSwarm::class]);

it('preserves durable native failures when failure listeners throw', function (string $swarm, bool $nativeThrows) {
    nativeOutcomeRuntime();
    config()->set('tests.native.throw', $nativeThrows);
    config()->set('swarm.durable.parallel.failure_policy', 'fail_run');
    $runId = $swarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    if ($swarm === ParallelSwarm::class) {
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
        $branch = collect(app(DurableRunStore::class)->branchesFor($runId))->firstWhere('agent_class', PendingAgent::class);
        $job = new AdvanceDurableBranch($runId, $branch['branch_id']);
    } else {
        $job = new AdvanceDurableSwarm($runId, 0);
    }
    $listeners = 0;
    app('events')->listen(SwarmFailed::class, function () use (&$listeners) {
        $listeners++;
        throw new RuntimeException('terminal failure listener unavailable');
    });
    nativeOutcomeAssertWorkerFailure($job, $nativeThrows ? ApprovalNotResumableException::class : UnsupportedNativeApprovalException::class);
    expect($listeners)->toBe(1)->and(PendingAgent::$calls)->toBe(1);
})->with([SequentialSwarm::class, ParallelSwarm::class])->with([false, true]);

it('preserves native rejection when unmatched stream call cleanup fails', function (string $path, bool $nativeThrows) {
    nativeOutcomeRuntime();
    config()->set('tests.native.unmatched_tool', true);
    config()->set('tests.native.throw', $nativeThrows);
    config()->set('swarm.streaming.integrity.enabled', true);
    $snapshots = Mockery::mock(SnapshotsMemory::class, app(SnapshotsMemory::class));
    $snapshots->shouldReceive('appendToolCall')->once()->andThrow(new RuntimeException('snapshot unavailable'));
    app()->instance(SnapshotsMemory::class, $snapshots);
    $exception = $nativeThrows ? ApprovalNotResumableException::class : UnsupportedNativeApprovalException::class;
    if (str_starts_with($path, 'live')) {
        $stream = match ($path) {
            'live-sequential' => app(SwarmRunner::class)->agent(new PendingAgent)->stream('task'),
            'live-static' => StaticSwarm::make()->stream('task'),
            'live-hierarchical' => HierarchicalSwarm::make()->stream('task'),
        };
        expect(fn () => iterator_to_array($stream))->toThrow($exception);
    } else {
        $swarm = match ($path) {
            'durable-sequential' => StreamingSequentialSwarm::class,
            'durable-static' => StreamingStaticSwarm::class,
            'durable-branch' => StreamingParallelSwarm::class,
        };
        $runId = $swarm::make()->dispatchDurable('task')->runId;
        $manager = app(DurableSwarmManager::class);
        $index = 0;
        if ($path !== 'durable-sequential') {
            (new AdvanceDurableSwarm($runId, 0))->handle($manager);
            $index = 1;
        }
        $branch = collect(app(DurableRunStore::class)->branchesFor($runId))->firstWhere('agent_class', PendingAgent::class);
        $job = $branch === null ? new AdvanceDurableSwarm($runId, $index) : new AdvanceDurableBranch($runId, $branch['branch_id']);
        $job->withFakeQueueInteractions();
        expect(fn () => $job->handle($manager))->toThrow($exception);
        $job->assertFailedWith($exception);
        $job->assertNotReleased();
        $row = $branch === null ? $manager->find($runId) : app(DurableRunStore::class)->findBranch($runId, $branch['branch_id']);
        expect($row['status'])->toBe('failed')->and($row['retry_attempt'])->toBe(0);
    }
    expect(PendingAgent::$calls)->toBe(1)
        ->and(ActiveRunContext::current())->toBeNull();
})->with(['live-sequential', 'live-static', 'live-hierarchical', 'durable-sequential', 'durable-static', 'durable-branch'])->with([false, true]);

it('fails the branch queue job if parent join dispatch is unavailable after native rejection', function (bool $nativeThrows) {
    nativeOutcomeRuntime();
    config()->set('tests.native.throw', $nativeThrows);
    config()->set('swarm.durable.parallel.failure_policy', 'partial_success');
    $store = Mockery::mock(DurableRunStore::class, app(DurableRunStore::class));
    $store->shouldReceive('releaseWaitingRunForJoin')->once()->andThrow(new RuntimeException('join unavailable'));
    app()->instance(DurableRunStore::class, $store);
    $runId = ParallelSwarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $branches = collect($store->branchesFor($runId));
    $writer = $branches->firstWhere('agent_class', FakeWriter::class);
    (new AdvanceDurableBranch($runId, $writer['branch_id']))->handle($manager);
    $branch = $branches->firstWhere('agent_class', PendingAgent::class);
    nativeOutcomeAssertWorkerFailure(new AdvanceDurableBranch($runId, $branch['branch_id']), $nativeThrows ? ApprovalNotResumableException::class : UnsupportedNativeApprovalException::class);
    expect($store->findBranch($runId, $branch['branch_id'])['status'])->toBe('failed')
        ->and($store->findBranch($runId, $branch['branch_id'])['retry_attempt'])->toBe(0)
        ->and($manager->find($runId)['status'])->toBe('waiting');
})->with([false, true]);

it('preserves live native rejection through failure listeners', function (string $path, bool $nativeThrows) {
    config()->set('tests.native.throw', $nativeThrows);
    $listeners = 0;
    app('events')->listen(SwarmFailed::class, function () use (&$listeners) {
        $listeners++;
        throw new RuntimeException('live failure listener unavailable');
    });
    $stream = match ($path) {
        'sequential' => app(SwarmRunner::class)->agent(new PendingAgent)->stream('task'),
        'static' => StaticSwarm::make()->stream('task'),
        'hierarchical' => HierarchicalSwarm::make()->stream('task'),
    };
    expect(fn () => iterator_to_array($stream))->toThrow($nativeThrows ? ApprovalNotResumableException::class : UnsupportedNativeApprovalException::class);
    expect($listeners)->toBe(1);
})->with(['sequential', 'static', 'hierarchical'])->with([false, true]);

it('persists rejected branch failure before fallible logging', function () {
    nativeOutcomeRuntime();
    $logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $logger->shouldReceive('error')->once()->andThrow(new RuntimeException('logging unavailable'));
    app()->instance(LoggerInterface::class, $logger);
    $runId = ParallelSwarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $branch = collect(app(DurableRunStore::class)->branchesFor($runId))->firstWhere('agent_class', PendingAgent::class);
    $job = (new AdvanceDurableBranch($runId, $branch['branch_id']))->withFakeQueueInteractions();
    expect(fn () => $job->handle($manager))->toThrow(UnsupportedNativeApprovalException::class);
    $job->assertFailedWith(UnsupportedNativeApprovalException::class);
    expect(app(DurableRunStore::class)->findBranch($runId, $branch['branch_id'])['status'])->toBe('failed');
});
