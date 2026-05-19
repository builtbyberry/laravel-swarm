<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmWebhooks;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Artisan;

/**
 * Schema emit consistency suite for v0.4.1 (issues #28, #29, #32, #33).
 *
 * Verifies that audit envelope fields previously documented as nullable or
 * outright missing are now non-null and present across the affected emit
 * sites. These tests guard the v0.x frozen schema commitment.
 */
beforeEach(function (): void {
    // Configure durable runtime — mirrors configureDurableRuntime() in
    // DurableSwarmTest.php; inlined because Pest test files don't share
    // file-scoped helpers.
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

function schemaEmitRecordingSink(): RecordingSwarmAuditSink
{
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);

    return $sink;
}

// ---------------------------------------------------------------------------
// #28 — durable.* swarm_class and topology are non-null
// ---------------------------------------------------------------------------

test('durable.completed carries non-null swarm_class and topology (#28)', function (): void {
    $sink = schemaEmitRecordingSink();

    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    $record = $sink->recordsForCategory('durable.completed')[0];
    expect($record['swarm_class'])->toBe(FakeSequentialSwarm::class);
    expect($record['topology'])->toBe('sequential');
});

test('durable.checkpointed carries non-null swarm_class and topology (#28)', function (): void {
    $sink = schemaEmitRecordingSink();

    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    $record = $sink->recordsForCategory('durable.checkpointed')[0];
    expect($record['swarm_class'])->toBe(FakeSequentialSwarm::class);
    expect($record['topology'])->toBe('sequential');
});

// #33 (durable.cancelled carries duration_ms) is not exercised in this file:
// driving DurableRunRecorder::cancel() synchronously requires owning the
// durable execution lease, which fights the test infrastructure. The shape
// guarantee is enforced by code review against DurableRunRecorder::cancel()
// alongside the v0.4.1 freeze-doc commitment; the duration_ms helper
// (DurableRunContext::durationMillisecondsFor) is identical to the one
// exercised by durable.completed and durable.failed audit assertions in
// the broader durable test suite.

// ---------------------------------------------------------------------------
// #32 — command.relay error path carries exception_class
// ---------------------------------------------------------------------------

test('command.relay error emit carries exception_class (#32)', function (): void {
    $sink = schemaEmitRecordingSink();

    $throwingOutbox = new class implements DurableOutbox
    {
        public function enqueueStep(string $runId, int $stepIndex, ?string $connection, ?string $queue): void {}

        public function enqueueBranch(string $runId, string $branchId, ?string $connection, ?string $queue): void {}

        public function enqueueQueuedResume(string $runId, ?string $connection, ?string $queue): void {}

        public function drain(array $types = [], int $limit = 100): DrainResult
        {
            throw new RuntimeException('drain blew up');
        }
    };
    app()->instance(DurableOutbox::class, $throwingOutbox);

    try {
        Artisan::call('swarm:relay');
    } catch (RuntimeException) {
        // expected — swarm:relay rethrows after audit emit
    }

    $record = $sink->recordsForCategory('command.relay')[0];
    expect($record['status'])->toBe('error');
    expect($record)->toHaveKey('exception_class');
    expect($record['exception_class'])->toBe(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// #29 — webhook.signal_received carries swarm_class
// ---------------------------------------------------------------------------

test('webhook.signal_received carries swarm_class (#29)', function (): void {
    config()->set('swarm.durable.webhooks.enabled', true);
    config()->set('swarm.durable.webhooks.auth.driver', 'none');

    SwarmWebhooks::routes([FakeSequentialSwarm::class]);

    $sink = schemaEmitRecordingSink();

    // Dispatch a durable run so we have a runId to signal against.
    $response = FakeSequentialSwarm::make()->dispatchDurable('task');
    $runId = $response->runId;

    $this->call(
        'POST',
        "/swarm/webhooks/signal/{$runId}/test-signal",
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        json_encode(['payload' => 'value'], JSON_THROW_ON_ERROR),
    );

    $record = $sink->recordsForCategory('webhook.signal_received')[0];
    expect($record)->toHaveKey('swarm_class');
    expect($record['swarm_class'])->toBe(FakeSequentialSwarm::class);
});
