<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
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
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Responses\AgentResponse;

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
        public function prompt(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
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

it('prevents Laravel workers from retrying either native approval failure with five tries', function (string $kind, bool $nativeThrows) {
    nativeOutcomeRuntime();
    config()->set('tests.native.throw', $nativeThrows);
    config()->set('swarm.queue.tries', 5);
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
    $context = RunContext::fromTask('task');
    $job = $kind === 'invoke'
        ? new InvokeSwarm(SequentialSwarm::class, $context->toQueuePayload())
        : new BroadcastSwarm(SequentialSwarm::class, $context->toQueuePayload(), ['test']);
    $queue = app('queue')->connection('native-worker');
    $queue->push($job);
    $queued = $queue->pop('test');
    expect($queued->maxTries())->toBe(5);
    $exception = $nativeThrows ? ApprovalNotResumableException::class : UnsupportedNativeApprovalException::class;
    expect(fn () => app('queue.worker')->process('native-worker', $queued, new WorkerOptions(maxTries: 5)))
        ->toThrow($exception);
    expect($queued->hasFailed())->toBeTrue()->and($queued->isReleased())->toBeFalse()
        ->and($queue->size('test'))->toBe(0)->and(PendingAgent::$calls)->toBe(1);
})->with(['invoke', 'broadcast'])->with([false, true]);

it('fails a queued hierarchical resume at the pending worker after a real parallel join', function () {
    nativeOutcomeRuntime();
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
    $resume = (new ResumeQueuedHierarchicalSwarm($context->runId))->withFakeQueueInteractions();
    expect(fn () => app()->call([$resume, 'handle']))->toThrow(UnsupportedNativeApprovalException::class);
    $resume->assertFailedWith(UnsupportedNativeApprovalException::class);
    $resume->assertNotReleased();
    expect(app(RunHistoryStore::class)->find($context->runId)['status'])->toBe('failed')
        ->and($manager->find($context->runId)['status'])->toBe('failed')
        ->and(PendingAgent::$calls)->toBe(1);
    Event::assertNotDispatched(SwarmCompleted::class);
});

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
