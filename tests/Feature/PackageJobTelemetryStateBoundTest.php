<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeAgentSettingsDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeAgentSettingsDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeNativeAgentSettingsQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeNativeInputQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Telemetry\PackageJobTelemetryState;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

// These tests read the state through the container and never rebind it: the
// queue-event listener is subscribed once at boot holding the bound instance,
// so a replacement bound afterwards would not be the one the listener uses.

class PackageJobTelemetryStateBoundUnrelatedJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void {}
}

function telemetryBoundSink(): RecordingSwarmTelemetrySink
{
    $telemetry = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $telemetry);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);

    return $telemetry;
}

/**
 * @param  array<int, Throwable|null>  $outcomes  One entry per expected handler call; null completes.
 */
function telemetryBoundManager(array $outcomes): void
{
    $manager = Mockery::mock(DurableSwarmManager::class);
    $manager->shouldReceive('advance')->times(count($outcomes))->andReturnUsing(function () use (&$outcomes): void {
        $outcome = array_shift($outcomes);

        if ($outcome !== null) {
            throw $outcome;
        }
    });
    app()->instance(DurableSwarmManager::class, $manager);

    $store = Mockery::mock(DurableRunStore::class);
    $store->shouldReceive('find')->andReturn(['swarm_class' => FakeSequentialSwarm::class]);
    app()->instance(DurableRunStore::class, $store);
}

function telemetryBoundProcess(QueueJobContract $queued, int $maxTries): ?Throwable
{
    try {
        app('queue.worker')->process('telemetry-bound', $queued, new WorkerOptions(maxTries: $maxTries));
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

function telemetryBoundQueue(): void
{
    config()->set('swarm.durable.job.tries', 5);
    config()->set('queue.connections.telemetry-bound', [
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
}

beforeEach(fn () => telemetryBoundQueue());

it('leaves no marker behind when a failed attempt is released for retry', function (string $jobClass) {
    $telemetry = telemetryBoundSink();
    $original = new LogicException('attempt failed');
    telemetryBoundManager([$original]);
    $queue = app('queue')->connection('telemetry-bound');
    $queue->push(new $jobClass('bound-released-run', 0));
    $queued = $queue->pop('test');

    $escaped = telemetryBoundProcess($queued, maxTries: 5);

    $failed = $telemetry->recordsForCategory('job.failed');

    expect($escaped)->toBe($original)
        ->and($queued->isReleased())->toBeTrue()
        ->and($queued->hasFailed())->toBeFalse()
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['job_class'])->toBe($jobClass)
        ->and($failed[0]['duration_ms'])->toBeInt()
        ->and(app(PackageJobTelemetryState::class)->pendingCount())->toBe(0);
})->with([
    'base job' => [AdvanceDurableSwarm::class],
    'job subclass' => [AdvanceNativeInputDurableSwarm::class],
]);

it('leaves no marker behind when a retried job then succeeds', function () {
    $telemetry = telemetryBoundSink();
    telemetryBoundManager([new LogicException('first attempt failed'), null]);
    $queue = app('queue')->connection('telemetry-bound');
    $queue->push(new AdvanceDurableSwarm('bound-retry-run', 0));

    $first = $queue->pop('test');
    telemetryBoundProcess($first, maxTries: 5);
    $this->travel(10)->minutes();
    $second = $queue->pop('test');
    $escaped = telemetryBoundProcess($second, maxTries: 5);

    $failed = $telemetry->recordsForCategory('job.failed');
    $completed = $telemetry->recordsForCategory('job.completed');

    expect($escaped)->toBeNull()
        ->and($second->attempts())->toBe(2)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['attempt'])->toBe(1)
        ->and($completed)->toHaveCount(1)
        ->and($completed[0]['attempt'])->toBe(2)
        ->and(app(PackageJobTelemetryState::class)->pendingCount())->toBe(0);
});

it('emits one job.failed for a final failure, also after the worker resets container scope', function (string $jobClass) {
    config()->set('swarm.durable.job.tries', 1);
    $telemetry = telemetryBoundSink();
    $original = new LogicException('final attempt failed');
    telemetryBoundManager([$original]);
    $queue = app('queue')->connection('telemetry-bound');
    $queue->push(new $jobClass('bound-final-run', 0));
    $queued = $queue->pop('test');

    // What the queue worker's daemon loop does before every job.
    app()->forgetScopedInstances();
    $escaped = telemetryBoundProcess($queued, maxTries: 1);

    $failed = $telemetry->recordsForCategory('job.failed');

    expect($escaped)->toBe($original)
        ->and($queued->hasFailed())->toBeTrue()
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['job_class'])->toBe($jobClass)
        ->and($failed[0]['duration_ms'])->toBeInt()
        ->and(app(PackageJobTelemetryState::class)->pendingCount())->toBe(0);
})->with([
    'base job' => [AdvanceDurableSwarm::class],
    'job subclass' => [AdvanceNativeInputDurableSwarm::class],
]);

it('emits one job.failed for a failure on the sync queue', function () {
    $telemetry = telemetryBoundSink();
    $original = new LogicException('sync attempt failed');
    telemetryBoundManager([$original]);

    expect(fn () => app('queue')->connection('sync')->push(new AdvanceDurableSwarm('bound-sync-run', 0)))
        ->toThrow(LogicException::class, 'sync attempt failed');

    $failed = $telemetry->recordsForCategory('job.failed');

    expect($failed)->toHaveCount(1)
        ->and($failed[0]['duration_ms'])->toBeInt()
        ->and(app(PackageJobTelemetryState::class)->pendingCount())->toBe(0);
});

it('keeps a pending marker when another job is attempted before the failure is reported', function () {
    $telemetry = telemetryBoundSink();
    telemetryBoundManager([new LogicException('outer attempt failed')]);
    $nested = 0;
    $pendingAfterNestedJob = null;
    Event::listen(JobExceptionOccurred::class, function () use (&$nested, &$pendingAfterNestedJob): void {
        if ($nested++ === 0) {
            app('queue')->connection('sync')->push(new PackageJobTelemetryStateBoundUnrelatedJob);
            $pendingAfterNestedJob = app(PackageJobTelemetryState::class)->pendingCount();
        }
    });

    expect(fn () => app('queue')->connection('sync')->push(new AdvanceDurableSwarm('bound-nested-run', 0)))
        ->toThrow(LogicException::class, 'outer attempt failed');

    expect($nested)->toBe(1)
        ->and($pendingAfterNestedJob)->toBe(1)
        ->and($telemetry->recordsForCategory('job.failed'))->toHaveCount(1)
        ->and(app(PackageJobTelemetryState::class)->pendingCount())->toBe(0);
});

it('still emits the fallback job.failed when the handler never ran', function (Closure $makeJob) {
    $telemetry = telemetryBoundSink();
    telemetryBoundManager([]);
    $queue = app('queue')->connection('telemetry-bound');
    $job = $makeJob();
    $queue->push($job);
    DB::connection('testing')->table('jobs')->update(['attempts' => 5]);
    $queued = $queue->pop('test');

    $escaped = telemetryBoundProcess($queued, maxTries: 5);

    $failed = $telemetry->recordsForCategory('job.failed');

    expect($escaped)->toBeInstanceOf(MaxAttemptsExceededException::class)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['run_id'])->toBe('bound-fallback-run')
        ->and($failed[0]['job_class'])->toBe($job::class)
        ->and($failed[0]['duration_ms'])->toBeNull()
        ->and(app(PackageJobTelemetryState::class)->pendingCount())->toBe(0);
})->with([
    'durable step' => [fn () => new AdvanceDurableSwarm('bound-fallback-run', 0)],
    'native-input durable step' => [fn () => new AdvanceNativeInputDurableSwarm('bound-fallback-run', 0)],
    'native-settings durable step' => [fn () => new AdvanceNativeAgentSettingsDurableSwarm('bound-fallback-run', 0)],
    'durable branch' => [fn () => new AdvanceDurableBranch('bound-fallback-run', 'branch-1')],
    'native-input durable branch' => [fn () => new AdvanceNativeInputDurableBranch('bound-fallback-run', 'branch-1')],
    'native-settings durable branch' => [fn () => new AdvanceNativeAgentSettingsDurableBranch('bound-fallback-run', 'branch-1')],
    'queued hierarchical resume' => [fn () => new ResumeQueuedHierarchicalSwarm('bound-fallback-run')],
    'native-input queued hierarchical resume' => [fn () => new ResumeNativeInputQueuedHierarchicalSwarm('bound-fallback-run')],
    'native-settings queued hierarchical resume' => [fn () => new ResumeNativeAgentSettingsQueuedHierarchicalSwarm('bound-fallback-run')],
]);

it('never lets a queue job that cannot report its id break the queue event', function () {
    $state = app(PackageJobTelemetryState::class);
    $state->markFailed('job:run:pending-uuid:1', 'pending-uuid');
    $job = new class extends FakeJob
    {
        public function getRawBody(): string
        {
            throw new RuntimeException('payload unavailable');
        }
    };

    event(new JobAttempted('telemetry-bound', $job));

    expect($state->pendingCount())->toBe(1);
});

it('holds at most the cap when telemetry event listening is disabled', function () {
    $cap = (new ReflectionClassConstant(PackageJobTelemetryState::class, 'MAX_PENDING'))->getValue();
    $flag = 'SWARM_OBSERVABILITY_LISTEN_EVENTS';
    $previous = [getenv($flag), $_ENV[$flag] ?? null, $_SERVER[$flag] ?? null];
    putenv("{$flag}=false");
    $_ENV[$flag] = $_SERVER[$flag] = 'false';

    try {
        $this->refreshApplication();
        telemetryBoundQueue();
        expect(config('swarm.observability.listen_to_events'))->toBeFalse();

        $telemetry = telemetryBoundSink();
        $failures = $cap + 5;
        telemetryBoundManager(array_fill(0, $failures, new LogicException('attempt failed')));
        $queue = app('queue')->connection('telemetry-bound');

        for ($run = 0; $run < $failures; $run++) {
            $queue->push(new AdvanceDurableSwarm("bound-unlistened-run-{$run}", 0));
            telemetryBoundProcess($queue->pop('test'), maxTries: 5);
            $this->travel(10)->minutes();
            DB::connection('testing')->table('jobs')->delete();
        }

        expect($telemetry->recordsForCategory('job.failed'))->toHaveCount($failures)
            ->and(app(PackageJobTelemetryState::class)->pendingCount())->toBe($cap);
    } finally {
        [$process, $env, $server] = $previous;
        $process === false ? putenv($flag) : putenv("{$flag}={$process}");

        if ($env === null) {
            unset($_ENV[$flag]);
        } else {
            $_ENV[$flag] = $env;
        }

        if ($server === null) {
            unset($_SERVER[$flag]);
        } else {
            $_SERVER[$flag] = $server;
        }
    }
});
