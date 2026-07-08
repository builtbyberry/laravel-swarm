<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpAuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseAuditOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRunInspector;
use Illuminate\Config\Repository;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Psr\Log\NullLogger;

/*
 * Part A of Phase 2 (laravel-swarm-filament): the public display read seams core
 * exposes so a companion can inspect durable + audit data without binding the
 * internal cipher or the strict-operational reads. Records 629/630/632.
 */

/** A sealed sw0: value encrypted under a foreign key — undecryptable here. */
function displaySeamsPoison(string $plaintext = 'unreadable'): string
{
    $foreign = new SwarmPersistenceCipher(
        new Repository(['swarm.persistence.encrypt_at_rest' => true, 'swarm.persistence.driver' => 'database']),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    return (string) $foreign->seal($plaintext);
}

function displaySeamsSeedRun(string $runId): void
{
    $now = now('UTC');

    DB::table('swarm_durable_runs')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'hierarchical',
        'execution_mode' => 'durable',
        'coordination_profile' => 'step_durable',
        'status' => 'pending',
        'next_step_index' => 0,
        'current_step_index' => null,
        'total_steps' => 1,
        'route_cursor' => null,
        'route_start_node_id' => null,
        'current_node_id' => null,
        'completed_node_ids' => json_encode([]),
        'timeout_at' => $now->copy()->addHour(),
        'step_timeout_seconds' => 300,
        'attempts' => 0,
        'lease_acquired_at' => null,
        'execution_token' => null,
        'leased_until' => null,
        'recovery_count' => 0,
        'last_recovered_at' => null,
        'pause_requested_at' => null,
        'paused_at' => null,
        'resumed_at' => null,
        'cancel_requested_at' => null,
        'cancelled_at' => null,
        'timed_out_at' => null,
        'wait_reason' => null,
        'waiting_since' => null,
        'wait_timeout_at' => null,
        'last_progress_at' => null,
        'retry_attempt' => 0,
        'next_retry_at' => null,
        'parent_run_id' => null,
        'queue_connection' => null,
        'queue_name' => null,
        'finished_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function displaySeamsUseDatabase(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(ReadableAuditOutbox::class);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
}

test('ReadableAuditOutbox binds the database outbox in database mode and the no-op otherwise', function () {
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(ReadableAuditOutbox::class);

    expect(app(ReadableAuditOutbox::class))->toBeInstanceOf(NoOpAuditOutbox::class);

    displaySeamsUseDatabase();

    expect(app(ReadableAuditOutbox::class))->toBeInstanceOf(DatabaseAuditOutbox::class)
        // Same instance as the AuditOutbox binding, not a second copy.
        ->and(app(ReadableAuditOutbox::class))->toBe(app(AuditOutbox::class));
});

test('InspectsDurableRuns resolves the read-only durable inspector', function () {
    expect(app(InspectsDurableRuns::class))->toBeInstanceOf(DurableRunInspector::class);
});

test('no-op outbox health reports an empty, unavailable outbox', function () {
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(ReadableAuditOutbox::class);

    $outbox = app(ReadableAuditOutbox::class);

    expect($outbox->isAvailable())->toBeFalse()
        ->and($outbox->pending())->toBe([])
        ->and($outbox->deadLettered())->toBe([])
        ->and($outbox->healthSummary())->toBe([
            'available' => false,
            'pending' => 0,
            'dead_letter' => 0,
            'reserved' => 0,
            'oldest_pending_at' => null,
        ]);
});

test('outbox list reads return metadata only (no payload) and never consume rows', function () {
    displaySeamsUseDatabase();

    /** @var DatabaseAuditOutbox $outbox */
    $outbox = app(ReadableAuditOutbox::class);

    $outbox->enqueue('run.started', ['run_id' => 'run-1', 'detail' => 'first']);
    $outbox->enqueue('step.completed', ['run_id' => 'run-2', 'detail' => 'second']);
    $outbox->enqueue('run.failed', ['run_id' => 'run-3'], deadLetter: true);

    $pending = $outbox->pending();
    $deadLettered = $outbox->deadLettered();

    // Newest first; metadata + last_error only — the full payload is NOT in the list.
    expect($pending)->toHaveCount(2)
        ->and($pending[0]['category'])->toBe('step.completed')
        ->and($pending[0]['run_id'])->toBe('run-2')
        ->and($pending[0]['status'])->toBe('pending')
        ->and($pending[0]['attempts'])->toBe(0)
        ->and($pending[0])->not->toHaveKey('payload')
        ->and($deadLettered)->toHaveCount(1)
        ->and($deadLettered[0]['category'])->toBe('run.failed');

    expect($outbox->healthSummary())->toMatchArray([
        'available' => true,
        'pending' => 2,
        'dead_letter' => 1,
        'reserved' => 0,
    ]);
    expect($outbox->healthSummary()['oldest_pending_at'])->not->toBeNull();

    // A pure SELECT never reserves or deletes — the drainer's rows are untouched.
    expect(DB::table('swarm_audit_outbox')->count())->toBe(3)
        ->and(DB::table('swarm_audit_outbox')->whereNotNull('reserved_at')->count())->toBe(0);
});

test('outbox record() detail read display-decrypts the full payload on demand', function () {
    displaySeamsUseDatabase();

    /** @var DatabaseAuditOutbox $outbox */
    $outbox = app(ReadableAuditOutbox::class);

    $outbox->enqueue('run.started', ['run_id' => 'run-9', 'detail' => 'the secret detail']);
    $id = (int) DB::table('swarm_audit_outbox')->where('run_id', 'run-9')->value('id');

    $record = $outbox->record($id);

    expect($record)->not->toBeNull()
        ->and($record['run_id'])->toBe('run-9')
        ->and($record['payload_available'])->toBeTrue()
        ->and($record['payload']['detail'])->toBe('the secret detail')
        ->and($outbox->record(999999))->toBeNull();
});

test('outbox record() degrades a poison payload instead of throwing under the throw policy', function () {
    displaySeamsUseDatabase();
    config()->set('swarm.persistence.decrypt_failure_policy', 'throw');

    /** @var DatabaseAuditOutbox $outbox */
    $outbox = app(ReadableAuditOutbox::class);

    $outbox->enqueue('run.started', ['run_id' => 'bad', 'detail' => 'unreadable']);
    $id = (int) DB::table('swarm_audit_outbox')->where('run_id', 'bad')->value('id');
    DB::table('swarm_audit_outbox')->where('id', $id)->update(['payload' => displaySeamsPoison()]);

    $record = $outbox->record($id);

    expect($record['payload_available'])->toBeFalse()
        ->and($record['payload'])->toBeNull();
});

test('hierarchical node output display read degrades a poison node without aborting the batch', function () {
    displaySeamsUseDatabase();
    config()->set('swarm.persistence.decrypt_failure_policy', 'throw');

    /** @var DatabaseDurableRunStore $store */
    $store = app(DatabaseDurableRunStore::class);
    $runId = 'hier-display-1';
    displaySeamsSeedRun($runId);

    // A cleanly sealed node output under the app key.
    $store->storeHierarchicalNodeOutput($runId, 'node-a', 'output A', 3600);

    // A poison node output sealed under a foreign key.
    $now = now('UTC');
    DB::table('swarm_durable_node_outputs')->insert([
        'run_id' => $runId,
        'node_id' => 'node-b',
        'output' => displaySeamsPoison('output B'),
        'expires_at' => $now->copy()->addHour(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $outputs = collect($store->hierarchicalNodeOutputsForInspection($runId))->keyBy('node_id');

    expect($outputs)->toHaveCount(2)
        ->and($outputs['node-a']['output'])->toBe('output A')
        ->and($outputs['node-a']['output_available'])->toBeTrue()
        ->and($outputs['node-b']['output'])->toBeNull()
        ->and($outputs['node-b']['output_available'])->toBeFalse();
});

test('ReadableRunHistoryStore resolves the same instance as RunHistoryStore', function () {
    displaySeamsUseDatabase();

    expect(app(ReadableRunHistoryStore::class))->toBe(app(RunHistoryStore::class));
});

test('run history findForDisplay degrades poison context, output, and steps without throwing', function () {
    displaySeamsUseDatabase();
    config()->set('swarm.persistence.decrypt_failure_policy', 'throw');
    app()->forgetInstance(SwarmPersistenceCipher::class);

    $cipher = app(SwarmPersistenceCipher::class);
    $runId = 'hist-display-1';
    $now = now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'completed',
        'context' => json_encode(['input' => displaySeamsPoison('ctx')]),
        'metadata' => json_encode([]),
        // Legacy JSON step: clean input, poison output.
        'steps' => json_encode([[
            'step_index' => 0,
            'agent_class' => 'Writer',
            'input' => $cipher->seal('legacy step input'),
            'output' => displaySeamsPoison('legacy step output'),
        ]]),
        'output' => displaySeamsPoison('run output'),
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => $now,
        'expires_at' => $now->copy()->addHour(),
        'execution_token' => null,
        'leased_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Normalized step row: poison input, clean output.
    DB::table('swarm_run_steps')->insert([
        'run_id' => $runId,
        'step_index' => 1,
        'agent_class' => 'Reviewer',
        'input' => displaySeamsPoison('normalized input'),
        'output' => $cipher->seal('normalized output'),
        'artifacts' => json_encode([]),
        'metadata' => json_encode([]),
        'expires_at' => $now->copy()->addHour(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $detail = app(ReadableRunHistoryStore::class)->findForDisplay($runId);

    // Run-level: poison context input + output degrade, never throw or leak.
    expect($detail)->not->toBeNull()
        ->and($detail['context']['input'])->toBeNull()
        ->and($detail['context_available'])->toBeFalse()
        ->and($detail['output'])->toBeNull()
        ->and($detail['output_available'])->toBeFalse();

    $steps = collect($detail['steps'])->keyBy('agent_class');

    // Legacy step (openStepIoForDisplay): clean input, poison output.
    expect($steps['Writer']['input'])->toBe('legacy step input')
        ->and($steps['Writer']['input_available'])->toBeTrue()
        ->and($steps['Writer']['output'])->toBeNull()
        ->and($steps['Writer']['output_available'])->toBeFalse();

    // Normalized step (swarm_run_steps): poison input, clean output.
    expect($steps['Reviewer']['input'])->toBeNull()
        ->and($steps['Reviewer']['input_available'])->toBeFalse()
        ->and($steps['Reviewer']['output'])->toBe('normalized output')
        ->and($steps['Reviewer']['output_available'])->toBeTrue();
});

test('InspectsDurableRuns::inspect assembles hierarchical node outputs into the detail', function () {
    displaySeamsUseDatabase();

    $runId = 'inspect-hier-1';
    displaySeamsSeedRun($runId);
    app(DatabaseDurableRunStore::class)->storeHierarchicalNodeOutput($runId, 'node-x', 'node x output', 3600);

    $detail = app(InspectsDurableRuns::class)->inspect($runId)->toArray();

    $outputs = collect($detail['hierarchical_node_outputs'])->keyBy('node_id');

    expect($detail['hierarchical_node_outputs'])->toHaveCount(1)
        ->and($outputs['node-x']['output'])->toBe('node x output')
        ->and($outputs['node-x']['output_available'])->toBeTrue();
});
