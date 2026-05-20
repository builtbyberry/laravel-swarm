<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\CountingThrowingSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    app()->forgetInstance(AuditOutbox::class);
});

function seedReconcileRow(string $status, array $overrides = []): int
{
    $payload = $overrides['payload'] ?? ['run_id' => 'r-test', 'category' => 'run.failed', 'secret' => 'top-secret'];
    $cipher = app(SwarmPersistenceCipher::class);
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    return (int) DB::table('swarm_audit_outbox')->insertGetId([
        'category' => $overrides['category'] ?? 'run.failed',
        'run_id' => $overrides['run_id'] ?? ($payload['run_id'] ?? null),
        'payload' => $cipher->seal($encoded),
        'attempts' => $overrides['attempts'] ?? 3,
        'status' => $status,
        'last_error' => isset($overrides['last_error']) ? $cipher->seal($overrides['last_error']) : null,
        'last_attempted_at' => $overrides['last_attempted_at'] ?? Carbon::now('UTC'),
        'reserved_at' => null,
        'created_at' => $overrides['created_at'] ?? Carbon::now('UTC')->subMinutes(5),
        'updated_at' => Carbon::now('UTC'),
    ]);
}

function reconcileRecordingSink(): RecordingSwarmAuditSink
{
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    return $sink;
}

// -----------------------------------------------------------------------------
// Cache-driver guard
// -----------------------------------------------------------------------------

test('swarm:audit:reconcile exits non-zero with a clear error when the cache driver is in use', function (): void {
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(AuditOutbox::class);

    $exit = Artisan::call('swarm:audit:reconcile');

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('database-backed audit outbox');
});

// -----------------------------------------------------------------------------
// List
// -----------------------------------------------------------------------------

test('list mode prints info when the outbox is empty', function (): void {
    $exit = Artisan::call('swarm:audit:reconcile');

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('No audit outbox rows match.');
});

test('list mode tabulates pending and dead_letter rows', function (): void {
    seedReconcileRow('pending');
    seedReconcileRow('dead_letter');

    $exit = Artisan::call('swarm:audit:reconcile');

    expect($exit)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('pending');
    expect($output)->toContain('dead_letter');
    expect($output)->toContain('run.failed');
});

test('list mode --status filter only returns matching rows', function (): void {
    seedReconcileRow('pending', ['run_id' => 'r-pending']);
    seedReconcileRow('dead_letter', ['run_id' => 'r-dead']);

    Artisan::call('swarm:audit:reconcile', ['--status' => 'dead_letter', '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['ok'])->toBeTrue();
    expect($decoded['rows'])->toHaveCount(1);
    expect($decoded['rows'][0]['status'])->toBe('dead_letter');
});

test('list mode rejects an unknown --status value', function (): void {
    $exit = Artisan::call('swarm:audit:reconcile', ['--status' => 'made-up']);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('--status');
});

test('list mode --limit caps rows and reports truncation', function (): void {
    seedReconcileRow('dead_letter');
    seedReconcileRow('dead_letter');
    seedReconcileRow('dead_letter');

    Artisan::call('swarm:audit:reconcile', ['--limit' => 2, '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['rows'])->toHaveCount(2);
    expect($decoded['truncated'])->toBeTrue();
    expect($decoded['limit'])->toBe(2);
});

test('list --json output shape includes the documented fields', function (): void {
    $id = seedReconcileRow('dead_letter', ['run_id' => 'r-1', 'attempts' => 4]);

    Artisan::call('swarm:audit:reconcile', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['rows'][0])
        ->toHaveKey('id')
        ->toHaveKey('status')
        ->toHaveKey('category')
        ->toHaveKey('run_id')
        ->toHaveKey('attempts')
        ->toHaveKey('last_attempted_at')
        ->toHaveKey('age');
    expect($decoded['rows'][0]['id'])->toBe($id);
    expect($decoded['rows'][0]['attempts'])->toBe(4);
});

// -----------------------------------------------------------------------------
// Show
// -----------------------------------------------------------------------------

test('--show prints metadata and unsealed payload + last_error for a dead_letter row', function (): void {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $id = seedReconcileRow('dead_letter', [
        'payload' => ['run_id' => 'r-x', 'category' => 'run.failed', 'secret' => 'visible-after-unseal'],
        'last_error' => 'downstream rejected with 500',
    ]);

    Artisan::call('swarm:audit:reconcile', ['--show' => (string) $id]);
    $output = Artisan::output();

    expect($output)->toContain('visible-after-unseal');
    expect($output)->toContain('downstream rejected with 500');
    expect($output)->toContain('dead_letter');
});

test('--show works for pending rows', function (): void {
    $id = seedReconcileRow('pending');

    $exit = Artisan::call('swarm:audit:reconcile', ['--show' => (string) $id]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('pending');
});

test('--show --json unseals payload into the response', function (): void {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $id = seedReconcileRow('dead_letter', [
        'payload' => ['run_id' => 'r-x', 'category' => 'run.failed', 'secret' => 'unsealed-payload-x'],
    ]);

    Artisan::call('swarm:audit:reconcile', ['--show' => (string) $id, '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['ok'])->toBeTrue();
    expect($decoded['row']['payload']['secret'])->toBe('unsealed-payload-x');
});

test('--show with an unknown id returns a clear error', function (): void {
    $exit = Artisan::call('swarm:audit:reconcile', ['--show' => '99999']);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('not found');
});

test('--show emits a command.audit_reconcile evidence record with action=show and no payload contents', function (): void {
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter', [
        'attempts' => 4,
        'run_id' => 'r-show',
        'payload' => ['run_id' => 'r-show', 'category' => 'run.failed', 'secret' => 'should-not-leak'],
    ]);

    $exit = Artisan::call('swarm:audit:reconcile', ['--show' => (string) $id]);

    expect($exit)->toBe(0);

    $records = $sink->recordsForCategory('command.audit_reconcile');
    expect($records)->toHaveCount(1);
    expect($records[0]['action'])->toBe('show');
    expect($records[0]['target_id'])->toBe($id);
    expect($records[0]['target_category'])->toBe('run.failed');
    expect($records[0]['target_run_id'])->toBe('r-show');
    expect($records[0]['prior_attempts'])->toBe(4);
    expect($records[0])->toHaveKey('target_created_at');
    expect($records[0])->toHaveKey('target_age_seconds');
    expect(array_keys($records[0]))->not->toContain('payload');
    expect(array_keys($records[0]))->not->toContain('reason');
    expect(array_keys($records[0]))->not->toContain('target_payload_digest');
});

test('--show --json includes audit_emitted=true on the successful read path', function (): void {
    reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter');

    Artisan::call('swarm:audit:reconcile', ['--show' => (string) $id, '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['ok'])->toBeTrue();
    expect($decoded['audit_emitted'])->toBeTrue();
});

test('--show exits non-zero with a clear warning when the audit emit fails, but still prints the row', function (): void {
    $id = seedReconcileRow('dead_letter', ['payload' => ['secret' => 'visible-x']]);
    bindFailingDispatcher();

    $exit = Artisan::call('swarm:audit:reconcile', ['--show' => (string) $id]);

    expect($exit)->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('visible-x');
    expect($output)->toContain('Read audit chain is broken');
});

// -----------------------------------------------------------------------------
// Requeue
// -----------------------------------------------------------------------------

test('--requeue resets a dead_letter row to pending with attempts=0 and preserves last_error', function (): void {
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter', ['attempts' => 5, 'last_error' => 'sink down for 24h']);

    $exit = Artisan::call('swarm:audit:reconcile', ['--requeue' => (string) $id, '--force' => true]);

    expect($exit)->toBe(0);

    $row = DB::table('swarm_audit_outbox')->where('id', $id)->first();
    expect($row->status)->toBe('pending');
    expect($row->attempts)->toBe(0);
    expect($row->reserved_at)->toBeNull();
    expect($row->last_error)->not->toBeNull();

    $records = $sink->recordsForCategory('command.audit_reconcile');
    expect($records)->toHaveCount(1);
    expect($records[0]['action'])->toBe('requeue');
    expect($records[0]['target_id'])->toBe($id);
    expect($records[0]['target_category'])->toBe('run.failed');
    expect($records[0]['prior_attempts'])->toBe(5);
});

test('--requeue with --reason records the reason on the command.audit_reconcile evidence', function (): void {
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter');

    Artisan::call('swarm:audit:reconcile', [
        '--requeue' => (string) $id,
        '--reason' => 'sink restored after maintenance window',
        '--force' => true,
    ]);

    $records = $sink->recordsForCategory('command.audit_reconcile');
    expect($records[0]['reason'])->toBe('sink restored after maintenance window');
});

test('--requeue rejects pending rows with a clear error', function (): void {
    reconcileRecordingSink();
    $id = seedReconcileRow('pending');

    $exit = Artisan::call('swarm:audit:reconcile', ['--requeue' => (string) $id, '--force' => true]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('only dead_letter rows can be requeued');

    $row = DB::table('swarm_audit_outbox')->where('id', $id)->first();
    expect($row->status)->toBe('pending');
});

test('--requeue rejects unknown ids', function (): void {
    reconcileRecordingSink();

    $exit = Artisan::call('swarm:audit:reconcile', ['--requeue' => '99999', '--force' => true]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('not found');
});

test('--requeue without --force aborts when no operator confirms (non-interactive)', function (): void {
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter');

    $exit = Artisan::call('swarm:audit:reconcile', ['--requeue' => (string) $id]);

    expect($exit)->toBe(1);

    $row = DB::table('swarm_audit_outbox')->where('id', $id)->first();
    expect($row->status)->toBe('dead_letter');
    expect($sink->recordsForCategory('command.audit_reconcile'))->toHaveCount(0);
});

test('--requeue --json without --force exits with a force_required error envelope', function (): void {
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter');

    $exit = Artisan::call('swarm:audit:reconcile', [
        '--requeue' => (string) $id,
        '--json' => true,
    ]);

    expect($exit)->toBe(1);

    $decoded = json_decode(Artisan::output(), true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['error'])->toBe('force_required');
    expect($decoded['message'])->toContain('Non-interactive automation requires --force');

    $row = DB::table('swarm_audit_outbox')->where('id', $id)->first();
    expect($row->status)->toBe('dead_letter');
    expect($sink->recordsForCategory('command.audit_reconcile'))->toHaveCount(0);
});

test('--dismiss --json without --force exits with a force_required error envelope', function (): void {
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter');

    $exit = Artisan::call('swarm:audit:reconcile', [
        '--dismiss' => (string) $id,
        '--reason' => 'scripted',
        '--json' => true,
    ]);

    expect($exit)->toBe(1);

    $decoded = json_decode(Artisan::output(), true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['error'])->toBe('force_required');
    expect($decoded['message'])->toContain('Non-interactive automation requires --force');

    expect(DB::table('swarm_audit_outbox')->where('id', $id)->exists())->toBeTrue();
    expect($sink->recordsForCategory('command.audit_reconcile'))->toHaveCount(0);
});

test('--requeue proceeds when confirmation is given interactively', function (): void {
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter');

    $this->artisan('swarm:audit:reconcile', ['--requeue' => (string) $id])
        ->expectsConfirmation('Requeue audit outbox row ['.$id.'] (category=run.failed, attempts=3)?', 'yes')
        ->assertSuccessful();

    expect(DB::table('swarm_audit_outbox')->where('id', $id)->value('status'))->toBe('pending');
    expect($sink->recordsForCategory('command.audit_reconcile'))->toHaveCount(1);
});

// -----------------------------------------------------------------------------
// Dismiss
// -----------------------------------------------------------------------------

test('--dismiss deletes a dead_letter row and emits the audit record with reason snapshot', function (): void {
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter', ['attempts' => 7, 'run_id' => 'r-dismiss']);

    $exit = Artisan::call('swarm:audit:reconcile', [
        '--dismiss' => (string) $id,
        '--reason' => 'duplicate of run.failed for r-7',
        '--force' => true,
    ]);

    expect($exit)->toBe(0);
    expect(DB::table('swarm_audit_outbox')->where('id', $id)->exists())->toBeFalse();

    $records = $sink->recordsForCategory('command.audit_reconcile');
    expect($records)->toHaveCount(1);
    expect($records[0]['action'])->toBe('dismiss');
    expect($records[0]['target_id'])->toBe($id);
    expect($records[0]['target_run_id'])->toBe('r-dismiss');
    expect($records[0]['target_category'])->toBe('run.failed');
    expect($records[0]['prior_attempts'])->toBe(7);
    expect($records[0]['reason'])->toBe('duplicate of run.failed for r-7');
});

test('--dismiss emits target_payload_digest as sha256 of the stored (sealed) payload bytes', function (): void {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter', [
        'payload' => ['run_id' => 'r-digest', 'category' => 'run.failed', 'secret' => 'forensic-digest-x'],
    ]);

    $storedPayload = (string) DB::table('swarm_audit_outbox')->where('id', $id)->value('payload');
    $expectedDigest = hash('sha256', $storedPayload);

    Artisan::call('swarm:audit:reconcile', [
        '--dismiss' => (string) $id,
        '--reason' => 'forensic digest test',
        '--force' => true,
    ]);

    $records = $sink->recordsForCategory('command.audit_reconcile');
    expect($records)->toHaveCount(1);
    expect($records[0])->toHaveKey('target_payload_digest');
    expect($records[0]['target_payload_digest'])->toMatch('/^[0-9a-f]{64}$/');
    expect($records[0]['target_payload_digest'])->toBe($expectedDigest);
});

test('--dismiss requires --reason and refuses without it', function (): void {
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter');

    $exit = Artisan::call('swarm:audit:reconcile', ['--dismiss' => (string) $id, '--force' => true]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('--reason');

    expect(DB::table('swarm_audit_outbox')->where('id', $id)->exists())->toBeTrue();
    expect($sink->recordsForCategory('command.audit_reconcile'))->toHaveCount(0);
});

test('--dismiss rejects pending rows', function (): void {
    reconcileRecordingSink();
    $id = seedReconcileRow('pending');

    $exit = Artisan::call('swarm:audit:reconcile', [
        '--dismiss' => (string) $id,
        '--reason' => 'wrong row',
        '--force' => true,
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('only dead_letter rows can be dismissed');
    expect(DB::table('swarm_audit_outbox')->where('id', $id)->exists())->toBeTrue();
});

test('--dismiss without --force aborts when no operator confirms (non-interactive)', function (): void {
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter');

    $exit = Artisan::call('swarm:audit:reconcile', [
        '--dismiss' => (string) $id,
        '--reason' => 'duplicate',
    ]);

    expect($exit)->toBe(1);
    expect(DB::table('swarm_audit_outbox')->where('id', $id)->exists())->toBeTrue();
    expect($sink->recordsForCategory('command.audit_reconcile'))->toHaveCount(0);
});

test('--dismiss proceeds when confirmation is given interactively', function (): void {
    $sink = reconcileRecordingSink();
    $id = seedReconcileRow('dead_letter');

    $this->artisan('swarm:audit:reconcile', [
        '--dismiss' => (string) $id,
        '--reason' => 'duplicate',
    ])
        ->expectsConfirmation('Permanently delete audit outbox row ['.$id.'] (category=run.failed, run_id=r-test)?', 'yes')
        ->assertSuccessful();

    expect(DB::table('swarm_audit_outbox')->where('id', $id)->exists())->toBeFalse();
    expect($sink->recordsForCategory('command.audit_reconcile'))->toHaveCount(1);
});

function bindFailingDispatcher(): void
{
    $stub = new class extends SwarmAuditDispatcher
    {
        public function __construct() {}

        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('emit blew up');
        }

        public function metadata(array $metadata): array
        {
            return ['metadata_keys' => [], 'metadata' => []];
        }
    };
    app()->instance(SwarmAuditDispatcher::class, $stub);
}

test('dismiss does NOT delete the row when the audit emit fails', function (): void {
    $id = seedReconcileRow('dead_letter');
    bindFailingDispatcher();

    $exit = Artisan::call('swarm:audit:reconcile', [
        '--dismiss' => (string) $id,
        '--reason' => 'attempted dismiss',
        '--force' => true,
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('command.audit_reconcile');
    expect(DB::table('swarm_audit_outbox')->where('id', $id)->exists())->toBeTrue();
});

test('requeue does NOT mutate the row when the audit emit fails', function (): void {
    $id = seedReconcileRow('dead_letter', ['attempts' => 4]);
    bindFailingDispatcher();

    $exit = Artisan::call('swarm:audit:reconcile', [
        '--requeue' => (string) $id,
        '--force' => true,
    ]);

    expect($exit)->toBe(1);
    $row = DB::table('swarm_audit_outbox')->where('id', $id)->first();
    expect($row->status)->toBe('dead_letter');
    expect($row->attempts)->toBe(4);
});

// -----------------------------------------------------------------------------
// Mutual exclusivity
// -----------------------------------------------------------------------------

test('combining --show with --requeue is rejected', function (): void {
    $id = seedReconcileRow('dead_letter');

    $exit = Artisan::call('swarm:audit:reconcile', [
        '--show' => (string) $id,
        '--requeue' => (string) $id,
        '--force' => true,
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('Use only one of');
});

// -----------------------------------------------------------------------------
// F6: evidence survives a failing sink under the default queue policy
// -----------------------------------------------------------------------------

test('--dismiss survives a failing sink under the default queue failure policy and preserves evidence in the outbox', function (): void {
    config()->set('swarm.audit.failure_policy', 'queue');

    $failingSink = new CountingThrowingSink;
    app()->instance(SwarmAuditSink::class, $failingSink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    $id = seedReconcileRow('dead_letter', [
        'run_id' => 'r-forensic',
        'attempts' => 5,
    ]);

    $exit = Artisan::call('swarm:audit:reconcile', [
        '--dismiss' => (string) $id,
        '--reason' => 'forensic test',
        '--force' => true,
    ]);

    expect($exit)->toBe(0);
    expect(DB::table('swarm_audit_outbox')->where('id', $id)->exists())->toBeFalse();

    $reconcileRow = DB::table('swarm_audit_outbox')
        ->where('category', 'command.audit_reconcile')
        ->first();

    expect($reconcileRow)->not->toBeNull();
    expect($reconcileRow->status)->toBe('pending');

    $unsealed = app(SwarmPersistenceCipher::class)->open((string) $reconcileRow->payload);
    expect($unsealed)->not->toBeNull();
    $decoded = json_decode((string) $unsealed, true);

    expect($decoded['action'])->toBe('dismiss');
    expect($decoded['target_id'])->toBe($id);
    expect($decoded['target_run_id'])->toBe('r-forensic');
    expect($decoded['prior_attempts'])->toBe(5);
    expect($decoded['reason'])->toBe('forensic test');
    expect($decoded)->toHaveKey('target_payload_digest');
});
