<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Telemetry\PackageJobTelemetryState;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;

beforeEach(function () {
    config()->set('swarm.queue.tries', 5);
    config()->set('swarm.durable.job.tries', 5);
    config()->set('queue.connections.telemetry-failure', [
        'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs',
        'queue' => 'test', 'retry_after' => 90,
    ]);
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

it('keeps native rejection permanent when failure telemetry also fails', function (string $kind, string $failure, string $exceptionClass) {
    $original = new $exceptionClass;
    $cleanup = new RuntimeException('failure telemetry unavailable');
    $calls = 0;
    [$service, $method] = match ($kind) {
        'step' => [DurableSwarmManager::class, 'advance'],
        'branch' => [DurableSwarmManager::class, 'advanceBranch'],
        'resume' => [QueuedHierarchicalCoordinator::class, 'resumeAfterParallelJoin'],
        'queued' => [SwarmRunner::class, 'runQueued'],
        'broadcast' => [SwarmRunner::class, 'stream'],
    };
    $handler = Mockery::mock($service);
    $handler->shouldReceive($method)->once()->andReturnUsing(function () use ($original, &$calls) {
        $calls++;
        throw $original;
    });
    app()->instance($service, $handler);
    $lookups = 0;
    $store = Mockery::mock(DurableRunStore::class);
    $store->shouldReceive('find')->andReturnUsing(function () use ($failure, $cleanup, &$lookups) {
        $lookups++;
        if ($failure === 'lookup' && $lookups === 2) {
            throw $cleanup;
        }

        return ['swarm_class' => FakeSequentialSwarm::class];
    });
    app()->instance(DurableRunStore::class, $store);
    if ($failure === 'state') {
        $state = Mockery::mock(PackageJobTelemetryState::class)->makePartial();
        $state->shouldReceive('markFailed')->once()->andThrow($cleanup);
        app()->instance(PackageJobTelemetryState::class, $state);
    }
    $payload = RunContext::from('task', 'telemetry-native-run')->toQueuePayload();
    $job = match ($kind) {
        'step' => new AdvanceDurableSwarm('telemetry-native-run', 0),
        'branch' => new AdvanceDurableBranch('telemetry-native-run', 'branch'),
        'resume' => new ResumeQueuedHierarchicalSwarm('telemetry-native-run'),
        'queued' => new InvokeSwarm(FakeSequentialSwarm::class, $payload),
        'broadcast' => new BroadcastSwarm(FakeSequentialSwarm::class, $payload, ['proof']),
    };
    $queue = app('queue')->connection('telemetry-failure');
    $queue->push($job);
    $queued = $queue->pop('test');
    expect($queued->maxTries())->toBe(5);
    $escaped = null;
    try {
        app('queue.worker')->process('telemetry-failure', $queued, new WorkerOptions(maxTries: 5));
    } catch (Throwable $exception) {
        $escaped = $exception;
    }
    expect($escaped)->toBe($original)
        ->and($queued->hasFailed())->toBeTrue()
        ->and($queued->isReleased())->toBeFalse()
        ->and($queue->size('test'))->toBe(0)
        ->and($calls)->toBe(1);
    if ($failure === 'lookup') {
        expect($lookups)->toBeGreaterThanOrEqual(2);
    }
})->with([
    ['step', 'lookup'], ['branch', 'lookup'], ['resume', 'lookup'],
    ['step', 'state'], ['branch', 'state'], ['resume', 'state'],
    ['queued', 'state'], ['broadcast', 'state'],
])->with([UnsupportedNativeApprovalException::class, ApprovalNotResumableException::class]);

it('retains ordinary failure telemetry exception and worker retry behavior', function () {
    $original = new LogicException('ordinary job failure');
    $cleanup = new RuntimeException('failure telemetry unavailable');
    $manager = Mockery::mock(DurableSwarmManager::class);
    $manager->shouldReceive('advance')->once()->andThrow($original);
    app()->instance(DurableSwarmManager::class, $manager);
    $store = Mockery::mock(DurableRunStore::class);
    $store->shouldReceive('find')->andReturn(['swarm_class' => FakeSequentialSwarm::class]);
    app()->instance(DurableRunStore::class, $store);
    $state = Mockery::mock(PackageJobTelemetryState::class)->makePartial();
    $state->shouldReceive('markFailed')->once()->andThrow($cleanup);
    app()->instance(PackageJobTelemetryState::class, $state);
    $queue = app('queue')->connection('telemetry-failure');
    $queue->push(new AdvanceDurableSwarm('ordinary-run', 0));
    $queued = $queue->pop('test');
    $escaped = null;
    try {
        app('queue.worker')->process('telemetry-failure', $queued, new WorkerOptions(maxTries: 5));
    } catch (Throwable $exception) {
        $escaped = $exception;
    }
    expect($escaped)->toBe($cleanup)
        ->and($queued->hasFailed())->toBeFalse()
        ->and($queued->isReleased())->toBeTrue()
        ->and($queue->size('test'))->toBe(1);
});
