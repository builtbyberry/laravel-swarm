<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Artisan;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function bindRecordingSink(): RecordingSwarmAuditSink
{
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    return $sink;
}

function configureDurableRuntimeForAudit(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-audit-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-audit-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable-audit');

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(DurableSwarmManager::class);
    app()->forgetInstance(SwarmAuditDispatcher::class);
}

// ---------------------------------------------------------------------------
// Lifecycle evidence
// ---------------------------------------------------------------------------

test('sequential run emits run.started, step and run.completed evidence', function (): void {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    $sink = bindRecordingSink();

    FakeSequentialSwarm::make()->run('original-task');

    expect($sink->hasCategory('run.started'))->toBeTrue();
    expect($sink->hasCategory('run.completed'))->toBeTrue();

    $started = $sink->recordsForCategory('run.started')[0];
    expect($started['swarm_class'])->toBe(FakeSequentialSwarm::class);
    expect($started['topology'])->toBe('sequential');
    expect($started['execution_mode'])->toBe('run');
    expect($started['status'])->toBe('started');

    $completed = $sink->recordsForCategory('run.completed')[0];
    expect($completed['swarm_class'])->toBe(FakeSequentialSwarm::class);
    expect($completed['status'])->toBe('completed');
    expect($completed['duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);

    $stepStarted = $sink->recordsForCategory('step.started');
    $stepCompleted = $sink->recordsForCategory('step.completed');
    expect($stepStarted)->toHaveCount(3);
    expect($stepCompleted)->toHaveCount(3);
    expect($stepStarted[0]['step_index'])->toBe(0);
    expect($stepCompleted[0]['step_index'])->toBe(0);
    expect($stepCompleted[0]['agent_class'])->toBe(FakeResearcher::class);
});

test('run.started evidence has schema_version and occurred_at', function (): void {
    FakeResearcher::fake(['out']);

    $sink = bindRecordingSink();
    FakeSequentialSwarm::make()->run('task');

    $record = $sink->recordsForCategory('run.started')[0];
    expect($record['schema_version'])->toBe(SwarmAuditDispatcher::SCHEMA_VERSION);
    expect($record)->toHaveKey('occurred_at');
    expect($record['occurred_at'])->toBeString()->not->toBeEmpty();
});

test('run.failed evidence is emitted when swarm throws', function (): void {
    $sink = bindRecordingSink();

    try {
        FailingQueuedSwarm::make()->run('fail-task');
    } catch (Throwable) {
    }

    expect($sink->hasCategory('run.started'))->toBeTrue();
    expect($sink->hasCategory('run.failed'))->toBeTrue();
    expect($sink->hasCategory('run.completed'))->toBeFalse();

    $failed = $sink->recordsForCategory('run.failed')[0];
    expect($failed['status'])->toBe('failed');
    expect($failed['exception_class'])->toBeString()->not->toBeEmpty();
    expect($failed['duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);
});

test('step evidence carries run_id and swarm_class', function (): void {
    FakeResearcher::fake(['step-out']);

    $sink = bindRecordingSink();
    FakeSequentialSwarm::make()->run('task');

    $runId = $sink->recordsForCategory('run.started')[0]['run_id'];
    foreach ($sink->recordsForCategory('step.started') as $record) {
        expect($record['run_id'])->toBe($runId);
        expect($record['swarm_class'])->toBe(FakeSequentialSwarm::class);
    }
});

// ---------------------------------------------------------------------------
// Privacy: capture disabled
// ---------------------------------------------------------------------------

test('evidence payloads do not include raw prompt text when capture is disabled', function (): void {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);

    $sink = bindRecordingSink();
    FakeSequentialSwarm::make()->run('SENSITIVE_PROMPT_TEXT');

    $allRecords = $sink->allRecords();

    foreach ($allRecords as $record) {
        $encoded = json_encode($record) ?: '';
        expect($encoded)->not->toContain('SENSITIVE_PROMPT_TEXT');
    }
});

// ---------------------------------------------------------------------------
// Prune command
// ---------------------------------------------------------------------------

test('swarm:prune command emits command.prune evidence with counts', function (): void {
    config()->set('swarm.persistence.driver', 'database');
    app()->forgetInstance(RunHistoryStore::class);

    $sink = bindRecordingSink();

    Artisan::call('swarm:prune');

    expect($sink->hasCategory('command.prune'))->toBeTrue();
    $record = $sink->recordsForCategory('command.prune')[0];
    expect($record['dry_run'])->toBeFalse();
    expect($record['prevent_prune'])->toBeFalse();
    expect($record['status'])->toBe('pruned');
    expect($record['counts'])->toBeArray();
});

test('swarm:prune --dry-run emits command.prune evidence with dry_run true', function (): void {
    config()->set('swarm.persistence.driver', 'database');
    app()->forgetInstance(RunHistoryStore::class);

    $sink = bindRecordingSink();

    Artisan::call('swarm:prune', ['--dry-run' => true]);

    expect($sink->hasCategory('command.prune'))->toBeTrue();
    $record = $sink->recordsForCategory('command.prune')[0];
    expect($record['dry_run'])->toBeTrue();
    expect($record['status'])->toBe('dry_run');
});

test('swarm:prune with prevent_prune emits skipped evidence', function (): void {
    config()->set('swarm.retention.prevent_prune', true);

    $sink = bindRecordingSink();

    Artisan::call('swarm:prune');

    expect($sink->hasCategory('command.prune'))->toBeTrue();
    $record = $sink->recordsForCategory('command.prune')[0];
    expect($record['status'])->toBe('skipped');
    expect($record['prevent_prune'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Recover command
// ---------------------------------------------------------------------------

test('swarm:recover command emits command.recover evidence with none_found status', function (): void {
    configureDurableRuntimeForAudit();
    $sink = bindRecordingSink();

    Artisan::call('swarm:recover');

    expect($sink->hasCategory('command.recover'))->toBeTrue();
    $record = $sink->recordsForCategory('command.recover')[0];
    expect($record['status'])->toBe('none_found');
    expect($record['recovered_count'])->toBe(0);
    expect($record['actor'])->toBe('artisan');
});

test('swarm:recover with targeted run-id emits evidence with target_run_id', function (): void {
    configureDurableRuntimeForAudit();
    $sink = bindRecordingSink();

    Artisan::call('swarm:recover', ['--run-id' => 'run-abc-123']);

    expect($sink->hasCategory('command.recover'))->toBeTrue();
    $record = $sink->recordsForCategory('command.recover')[0];
    expect($record['target_run_id'])->toBe('run-abc-123');
});

// ---------------------------------------------------------------------------
// Durable wait / signal evidence
// ---------------------------------------------------------------------------

test('durable wait emits wait.created evidence', function (): void {
    configureDurableRuntimeForAudit();
    $sink = bindRecordingSink();

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $manager = app(DurableSwarmManager::class);

    $manager->wait($response->runId, 'approval_received', 'Waiting for approval', 3600);

    expect($sink->hasCategory('wait.created'))->toBeTrue();
    $record = $sink->recordsForCategory('wait.created')[0];
    expect($record['run_id'])->toBe($response->runId);
    expect($record['wait_name'])->toBe('approval_received');
    expect($record['reason'])->toBe('Waiting for approval');
    expect($record['timeout_seconds'])->toBe(3600);
    expect($record['status'])->toBe('waiting');
});

test('accepted signal emits signal.received evidence with accepted true', function (): void {
    configureDurableRuntimeForAudit();
    $sink = bindRecordingSink();

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $manager = app(DurableSwarmManager::class);

    $manager->wait($response->runId, 'approval_received', 'Waiting', 3600);
    $result = $response->signal('approval_received', ['approved' => true]);

    expect($result->accepted)->toBeTrue();
    expect($sink->hasCategory('signal.received'))->toBeTrue();

    $record = $sink->recordsForCategory('signal.received')[0];
    expect($record['run_id'])->toBe($response->runId);
    expect($record['signal_name'])->toBe('approval_received');
    expect($record['accepted'])->toBeTrue();
    expect($record['duplicate'])->toBeFalse();
    expect($record['status'])->toBe('accepted');
});

test('non-accepted signal emits signal.received evidence with accepted false', function (): void {
    configureDurableRuntimeForAudit();
    $sink = bindRecordingSink();

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    // Do NOT set the run to waiting — signal should record but not accept.
    $result = $response->signal('some_event', ['data' => 'x']);

    expect($result->accepted)->toBeFalse();
    expect($sink->hasCategory('signal.received'))->toBeTrue();

    $record = $sink->recordsForCategory('signal.received')[0];
    expect($record['accepted'])->toBeFalse();
    expect($record['signal_name'])->toBe('some_event');
});
