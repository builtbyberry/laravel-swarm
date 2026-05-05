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
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
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
        ->and($telemetrySteps[0]['run_id'])->toBe($runId);
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
