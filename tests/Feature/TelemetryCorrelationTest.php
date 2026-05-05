<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryEventListener;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Artisan;

function bindRecordingAuditAndTelemetrySinks(): array
{
    $audit = new RecordingSwarmAuditSink;
    $telemetry = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmAuditSink::class, $audit);
    app()->instance(SwarmTelemetrySink::class, $telemetry);
    app()->forgetInstance(SwarmAuditDispatcher::class);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);

    return [$audit, $telemetry];
}

function configureTelemetryDurableRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.telemetry-durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'telemetry-durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-telemetry-durable');
    config()->set('swarm.capture.active_context', true);

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(DurableSwarmManager::class);
    app()->forgetInstance(SwarmAuditDispatcher::class);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);
}

function fakeQueueJobForCommand(object $command, int $attempts = 1, string $connection = 'sync', string $queue = 'sync'): FakeJob
{
    $detached = clone $command;

    if (property_exists($detached, 'job')) {
        $detached->job = null;
    }

    return new class($command, serialize($detached), $attempts, $connection, $queue) extends FakeJob
    {
        public function __construct(
            protected object $command,
            protected string $serializedCommand,
            int $attempts,
            string $connection,
            string $queue,
        ) {
            $this->attempts = $attempts;
            $this->connectionName = $connection;
            $this->queue = $queue;
        }

        public function getRawBody(): string
        {
            return json_encode([
                'uuid' => 'telemetry-fake-queue-job-uuid',
                'data' => [
                    'commandName' => $this->command::class,
                    'command' => $this->serializedCommand,
                ],
            ], JSON_THROW_ON_ERROR);
        }
    };
}

test('sync run correlates audit and telemetry run.started and run.completed', function (): void {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    [$audit, $telemetry] = bindRecordingAuditAndTelemetrySinks();

    FakeSequentialSwarm::make()->run('sync-task');

    $auditStarted = $audit->recordsForCategory('run.started')[0];
    $telemetryStarted = $telemetry->recordsForCategory('run.started')[0];

    expect($telemetryStarted['run_id'])->toBe($auditStarted['run_id'])
        ->and($telemetryStarted['swarm_class'])->toBe($auditStarted['swarm_class'])
        ->and($telemetryStarted['topology'])->toBe($auditStarted['topology'])
        ->and($telemetryStarted['execution_mode'])->toBe($auditStarted['execution_mode']);

    $auditCompleted = $audit->recordsForCategory('run.completed')[0];
    $telemetryCompleted = $telemetry->recordsForCategory('run.completed')[0];

    expect($telemetryCompleted['run_id'])->toBe($auditCompleted['run_id'])
        ->and($telemetryCompleted['duration_ms'])->toBe($auditCompleted['duration_ms']);
});

test('queued run correlates audit and telemetry when queue uses sync driver', function (): void {
    config(['queue.default' => 'sync']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    [$audit, $telemetry] = bindRecordingAuditAndTelemetrySinks();

    FakeSequentialSwarm::make()->queue('queued-task');

    $auditStarted = $audit->recordsForCategory('run.started')[0];
    $telemetryStarted = $telemetry->recordsForCategory('run.started')[0];

    expect($telemetryStarted['run_id'])->toBe($auditStarted['run_id'])
        ->and($telemetryStarted['execution_mode'])->toBe('queue');

    $jobStarted = $telemetry->recordsForCategory('job.started')[0];
    expect($jobStarted['run_id'])->toBe($auditStarted['run_id'])
        ->and($jobStarted['job_class'])->toBe(InvokeSwarm::class)
        ->and($jobStarted['attempt'])->toBe(1)
        ->and($jobStarted['queue_wait_ms'])->toBeInt()
        ->and($jobStarted['queue_wait_ms'])->toBeGreaterThanOrEqual(0);

    expect($telemetry->recordsForCategory('job.completed'))->toHaveCount(1);
    $jobCompleted = $telemetry->recordsForCategory('job.completed')[0];
    expect($jobCompleted['run_id'])->toBe($auditStarted['run_id'])
        ->and($jobCompleted['duration_ms'])->toBeInt()
        ->and($jobCompleted['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($jobCompleted['total_elapsed_ms'])->toBeInt()
        ->and($jobCompleted['total_elapsed_ms'])->toBeGreaterThanOrEqual($jobCompleted['duration_ms']);
});

test('durable advance step correlates audit and telemetry run lifecycle', function (): void {
    configureTelemetryDurableRuntime();
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    [$audit, $telemetry] = bindRecordingAuditAndTelemetrySinks();

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-correlation-task');
    $runId = $response->runId;

    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));

    // Durable first advance may not re-dispatch SwarmStarted when the run row
    // already advanced past the initial cursor; step evidence is the stable
    // correlation surface for this integration.
    $auditSteps = $audit->recordsForCategory('step.started');
    $telemetrySteps = $telemetry->recordsForCategory('step.started');

    expect($auditSteps)->not->toBeEmpty()
        ->and($telemetrySteps)->not->toBeEmpty()
        ->and($telemetrySteps[0]['run_id'])->toBe($auditSteps[0]['run_id'])
        ->and($telemetrySteps[0]['run_id'])->toBe($runId)
        ->and($telemetrySteps[0]['execution_mode'])->toBe('durable');
});

test('sync step telemetry includes execution mode', function (): void {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    [, $telemetry] = bindRecordingAuditAndTelemetrySinks();

    FakeSequentialSwarm::make()->run('sync-step-correlation-task');

    $started = $telemetry->recordsForCategory('step.started')[0];
    $completed = $telemetry->recordsForCategory('step.completed')[0];

    expect($started['execution_mode'])->toBe('run')
        ->and($started['topology'])->toBe('sequential')
        ->and($completed['execution_mode'])->toBe('run')
        ->and($completed['topology'])->toBe('sequential');
});

test('durable progress telemetry includes swarm and topology correlation fields', function (): void {
    configureTelemetryDurableRuntime();
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    [, $telemetry] = bindRecordingAuditAndTelemetrySinks();

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-progress-task');

    app(DurableSwarmManager::class)->recordProgress($response->runId, null, ['stage' => 'fetching']);

    $progress = $telemetry->recordsForCategory('progress.recorded')[0];
    expect($progress['run_id'])->toBe($response->runId)
        ->and($progress['swarm_class'])->toBe(FakeSequentialSwarm::class)
        ->and($progress['topology'])->toBe('sequential')
        ->and($progress['execution_mode'])->toBe('durable');
});

test('package job failure telemetry includes worker attempt duration and rethrows', function (): void {
    [, $telemetry] = bindRecordingAuditAndTelemetrySinks();

    $context = RunContext::from('failing-queued-task', 'telemetry-failing-job-run-id');
    $job = new InvokeSwarm(FailingQueuedSwarm::class, $context->toQueuePayload(), enqueuedAtMs: ((int) floor(microtime(true) * 1000)) - 50);

    expect(fn () => $job->handle(app(SwarmRunner::class)))
        ->toThrow(RuntimeException::class, 'Queued swarm failed.');

    $failed = $telemetry->recordsForCategory('job.failed')[0];
    expect($failed['run_id'])->toBe('telemetry-failing-job-run-id')
        ->and($failed['job_class'])->toBe(InvokeSwarm::class)
        ->and($failed['swarm_class'])->toBe(FailingQueuedSwarm::class)
        ->and($failed['duration_ms'])->toBeInt()
        ->and($failed['queue_wait_ms'])->toBeInt()
        ->and($failed['total_elapsed_ms'])->toBeInt()
        ->and($failed['exception_class'])->toBe(RuntimeException::class);
});

test('queue failure fallback does not duplicate handler emitted job failure telemetry', function (): void {
    [, $telemetry] = bindRecordingAuditAndTelemetrySinks();

    $context = RunContext::from('failing-queued-task', 'telemetry-duplicate-suppression-run-id');
    $command = new InvokeSwarm(FailingQueuedSwarm::class, $context->toQueuePayload(), enqueuedAtMs: ((int) floor(microtime(true) * 1000)) - 50);
    $queueJob = fakeQueueJobForCommand($command, attempts: 2, connection: 'redis', queue: 'swarm-testing');
    $command->job = $queueJob;

    try {
        $command->handle(app(SwarmRunner::class));
        $this->fail('Expected the queued swarm to throw.');
    } catch (RuntimeException $exception) {
        app(SwarmTelemetryEventListener::class)->handleJobFailed(new JobFailed('redis', $queueJob, $exception));
    }

    $failed = $telemetry->recordsForCategory('job.failed');

    expect($failed)->toHaveCount(1)
        ->and($failed[0]['attempt'])->toBe(2)
        ->and($failed[0]['queue_connection'])->toBe('redis')
        ->and($failed[0]['queue_name'])->toBe('swarm-testing')
        ->and($failed[0]['duration_ms'])->toBeInt();
});

test('durable package job telemetry includes routing and timing fields', function (): void {
    configureTelemetryDurableRuntime();
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    [, $telemetry] = bindRecordingAuditAndTelemetrySinks();

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-job-telemetry-task');
    $job = new AdvanceDurableSwarm($response->runId, 0, enqueuedAtMs: ((int) floor(microtime(true) * 1000)) - 50);
    $job->onConnection('telemetry-durable-test');
    $job->onQueue('swarm-telemetry-durable');

    $job->handle(app(DurableSwarmManager::class));

    $started = $telemetry->recordsForCategory('job.started')[0];
    $completed = $telemetry->recordsForCategory('job.completed')[0];

    expect($started['run_id'])->toBe($response->runId)
        ->and($started['swarm_class'])->toBe(FakeSequentialSwarm::class)
        ->and($started['job_class'])->toBe(AdvanceDurableSwarm::class)
        ->and($started['queue_connection'])->toBe('telemetry-durable-test')
        ->and($started['queue_name'])->toBe('swarm-telemetry-durable')
        ->and($started['queue_wait_ms'])->toBeInt()
        ->and($completed['run_id'])->toBe($response->runId)
        ->and($completed['duration_ms'])->toBeInt()
        ->and($completed['total_elapsed_ms'])->toBeInt();
});

test('telemetry payloads omit sensitive strings when capture is disabled', function (): void {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);
    config()->set('swarm.observability.metadata_allowlist', []);

    [, $telemetry] = bindRecordingAuditAndTelemetrySinks();

    FakeSequentialSwarm::make()->run('SENSITIVE_PROMPT_TELEMETRY');

    foreach ($telemetry->allRecords() as $record) {
        $encoded = json_encode($record) ?: '';
        expect($encoded)->not->toContain('SENSITIVE_PROMPT_TELEMETRY');
    }
});

test('telemetry metadata allowlist mirrors dispatcher policy', function (): void {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    config()->set('swarm.observability.metadata_allowlist', ['tenant_id']);

    $context = RunContext::from([
        'input' => 'task',
        'metadata' => [
            'tenant_id' => 'acme-corp',
            'secret_note' => 'SENSITIVE_META_TELEMETRY',
        ],
    ]);

    [, $telemetry] = bindRecordingAuditAndTelemetrySinks();

    FakeSequentialSwarm::make()->run($context);

    $started = $telemetry->recordsForCategory('run.started')[0];
    expect($started['metadata_keys'])->toContain('tenant_id', 'secret_note');
    expect($started['metadata'])->toBe(['tenant_id' => 'acme-corp']);

    foreach ($telemetry->allRecords() as $record) {
        $encoded = json_encode($record) ?: '';
        expect($encoded)->not->toContain('SENSITIVE_META_TELEMETRY');
    }
});
