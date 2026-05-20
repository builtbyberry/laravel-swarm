<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\CountingThrowingSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issue #41 — End-to-end audit chain with mid-flight signer rotation.
 *
 * Extends the in-isolation `DatabaseAuditOutbox preserves the original signer
 * key across replay` test (`tests/Unit/Audit/AuditOutboxTest.php`) into the
 * full production chain that an auditor reconstructs:
 *
 *   enqueue → drain attempt → transient failure → re-attempt →
 *   eventual success OR dead-letter
 *
 * with the signer key rotating mid-flight. The chain integrity properties
 * (signature stability, attempt-count progression, dead-letter logging,
 * sealed-at-rest fields) all hold as one cohesive story.
 *
 * Signer-rotation mechanism — we use a mutable key-holder backing an inline
 * `SwarmAuditSigner` (mirroring the existing in-isolation regression test in
 * `tests/Unit/Audit/AuditOutboxTest.php`). The new `SwarmFake` intercepts
 * (PR #74) are great when the test wants to *observe* signer invocations and
 * decisions, but here we need to *change the signer's output between
 * attempts*. Mutating a key-holder reads cleaner than juggling delegate
 * swaps mid-test, so the existing pattern is the right tool. Both
 * approaches are sanctioned by the issue.
 */

/**
 * Bind the outbox / dispatcher / sink / signer wiring for a single
 * end-to-end scenario.
 *
 * @return object{currentKey: string} The key-holder. Mutate ->currentKey to
 *                                    rotate the signer between attempts.
 */
function bindAuditChain(SwarmAuditSink $sink, bool $encryptAtRest): object
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', $encryptAtRest);
    config()->set('swarm.audit.failure_policy', 'queue');

    $keyHolder = new class
    {
        public string $currentKey = 'K1';
    };

    $signer = new class($keyHolder) implements SwarmAuditSigner
    {
        public function __construct(private readonly object $keyHolder) {}

        public function sign(string $category, array $payload): array
        {
            $payload['signature_key'] = $this->keyHolder->currentKey;

            return $payload;
        }
    };

    app()->instance(SwarmAuditSigner::class, $signer);
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    return $keyHolder;
}

// ---------------------------------------------------------------------------
// Scenario 1 — Happy-path: signer rotates between enqueue and successful replay
// ---------------------------------------------------------------------------

it('replays an outbox row with the original signer key when the signer rotates between enqueue and a successful drain', function (bool $encryptAtRest): void {
    $failingSink = new CountingThrowingSink;
    $keyHolder = bindAuditChain($failingSink, $encryptAtRest);

    // First emit: signed under K1, sink rejects, dispatcher routes to outbox.
    app(SwarmAuditDispatcher::class)->emit('run.failed', [
        'run_id' => 'r-happy',
        'category' => 'run.failed',
    ]);

    expect($failingSink->attempts)->toBe(1);

    $row = DB::table('swarm_audit_outbox')->where('run_id', 'r-happy')->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe('pending');
    expect($row->attempts)->toBe(0);

    // Encryption surface: the persisted payload column is sealed only when
    // encrypt_at_rest is enabled. We can't assert the K1 marker is absent
    // from the ciphertext directly (the base64 alphabet covers "K1" as a
    // substring by chance), so we cross-check via the unsealed payload at
    // the bottom of the scenario. Sealing itself is verified by the `sw0:`
    // prefix here.
    if ($encryptAtRest) {
        expect($row->payload)->toStartWith('sw0:');
    } else {
        expect($row->payload)->not->toStartWith('sw0:');
        expect($row->payload)->toContain('K1');
    }

    // Rotate signer to K2 and swap in a passing sink for replay.
    $keyHolder->currentKey = 'K2';
    $passingSink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $passingSink);
    app()->forgetInstance(AuditOutbox::class);

    $result = app(AuditOutbox::class)->drain();

    expect($result->replayed)->toBe(1);
    expect($result->failed)->toBe(0);
    expect(DB::table('swarm_audit_outbox')->where('run_id', 'r-happy')->count())->toBe(0);

    // The replayed sink received the K1-signed payload, not the rotated K2.
    $replayed = $passingSink->recordsForCategory('run.failed');
    expect($replayed)->toHaveCount(1);
    expect($replayed[0]['signature_key'])->toBe('K1');
    expect($replayed[0]['run_id'])->toBe('r-happy');
})->with([
    'encrypt_at_rest disabled' => false,
    'encrypt_at_rest enabled' => true,
]);

// ---------------------------------------------------------------------------
// Scenario 2 — Full chain to dead_letter under mid-flight signer rotation
// ---------------------------------------------------------------------------

it('drives a row from enqueue through repeated transient failures to dead_letter while preserving the K1 signature across rotation', function (bool $encryptAtRest): void {
    config()->set('swarm.audit.outbox.max_attempts', 2);

    Log::spy();

    $failingSink = new class implements SwarmAuditSink
    {
        public int $attempts = 0;

        public function emit(string $category, array $payload): void
        {
            $this->attempts++;
            throw new RuntimeException("sensitive-failure-detail #{$this->attempts}");
        }
    };

    $keyHolder = bindAuditChain($failingSink, $encryptAtRest);

    // Initial emit signed under K1: sink throws, row routed to outbox with
    // the K1 signature persisted on the payload column.
    app(SwarmAuditDispatcher::class)->emit('run.failed', [
        'run_id' => 'r-chain',
        'category' => 'run.failed',
    ]);

    expect($failingSink->attempts)->toBe(1);

    $row = DB::table('swarm_audit_outbox')->where('run_id', 'r-chain')->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe('pending');
    expect($row->attempts)->toBe(0);

    // Rotate signer to K2 between attempts. From this point on, any fresh
    // emit would carry K2, but the persisted outbox row must keep K1.
    $keyHolder->currentKey = 'K2';

    // First drain attempt under K2 binding: sink throws again. The dispatcher
    // does NOT re-sign on drain, so the row's payload stays K1-stamped.
    $outbox = app(AuditOutbox::class);

    $first = $outbox->drain();
    expect($first->failed)->toBe(1);
    expect($first->replayed)->toBe(0);
    expect($first->deadLettered)->toBe(0);

    $row = DB::table('swarm_audit_outbox')->where('run_id', 'r-chain')->first();
    expect($row->status)->toBe('pending');
    expect($row->attempts)->toBe(1);

    // last_error is sealed when encrypt_at_rest is enabled, plaintext otherwise.
    expect($row->last_error)->not->toBeNull();
    if ($encryptAtRest) {
        expect($row->last_error)->toStartWith('sw0:');
        expect($row->last_error)->not->toContain('sensitive-failure-detail');
    } else {
        expect($row->last_error)->not->toStartWith('sw0:');
        expect($row->last_error)->toContain('sensitive-failure-detail');
    }

    // The persisted payload still binds to K1, never K2, regardless of
    // encryption mode. Inspect through the same cipher the outbox uses on
    // its read path — `open()` is a no-op for plaintext rows.
    $cipher = app(SwarmPersistenceCipher::class);
    $decodedPayload = json_decode((string) $cipher->open($row->payload), associative: true);
    expect($decodedPayload['signature_key'])->toBe('K1');

    // Second drain attempt: sink throws once more. attempts hits
    // max_attempts (2), the row transitions to dead_letter, and Log::error
    // fires once with the K1-bound terminal state.
    $second = $outbox->drain();
    expect($second->failed)->toBe(0);
    expect($second->replayed)->toBe(0);
    expect($second->deadLettered)->toBe(1);

    $row = DB::table('swarm_audit_outbox')->where('run_id', 'r-chain')->first();
    expect($row->status)->toBe('dead_letter');
    expect($row->attempts)->toBe(2);

    // Terminal payload binding is still K1 even though the signer has been
    // rotated to K2 for the entire drain phase.
    $decodedPayload = json_decode((string) $cipher->open($row->payload), associative: true);
    expect($decodedPayload['signature_key'])->toBe('K1');

    // The dead_letter Log::error event fires exactly once and carries the
    // K1-bound terminal attempts count. The dispatcher does not re-emit
    // through the signer on the dead-letter transition.
    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'dead_letter')
                && ($context['run_id'] ?? null) === 'r-chain'
                && ($context['category'] ?? null) === 'run.failed'
                && ($context['attempts'] ?? null) === 2;
        })
        ->once();
})->with([
    'encrypt_at_rest disabled' => false,
    'encrypt_at_rest enabled' => true,
]);
