<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpAuditOutbox;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseAuditOutbox;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
});

test('container binds DatabaseAuditOutbox when persistence driver is database', function (): void {
    expect(app(AuditOutbox::class))->toBeInstanceOf(DatabaseAuditOutbox::class);
});

test('container binds NoOpAuditOutbox when persistence driver is cache', function (): void {
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(AuditOutbox::class);

    expect(app(AuditOutbox::class))->toBeInstanceOf(NoOpAuditOutbox::class);
});

test('NoOpAuditOutbox reports isAvailable() false', function (): void {
    expect((new NoOpAuditOutbox)->isAvailable())->toBeFalse();
});

test('NoOpAuditOutbox drain returns a zero result', function (): void {
    $result = (new NoOpAuditOutbox)->drain();

    expect($result->replayed)->toBe(0);
    expect($result->deadLettered)->toBe(0);
    expect($result->total())->toBe(0);
});

test('DatabaseAuditOutbox enqueue persists a pending row', function (): void {
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', [
        'run_id' => 'r-1',
        'category' => 'run.failed',
        'schema_version' => '2',
        'status' => 'failed',
    ]);

    $row = DB::table('swarm_audit_outbox')->first();
    expect($row)->not->toBeNull();
    expect($row->category)->toBe('run.failed');
    expect($row->run_id)->toBe('r-1');
    expect($row->status)->toBe('pending');
    expect($row->attempts)->toBe(0);
});

test('DatabaseAuditOutbox enqueue with deadLetter=true persists a dead_letter row directly', function (): void {
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-1'], deadLetter: true);

    $row = DB::table('swarm_audit_outbox')->first();
    expect($row->status)->toBe('dead_letter');
});

test('DatabaseAuditOutbox drain replays pending rows through the bound sink and deletes them', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(AuditOutbox::class);
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-1', 'category' => 'run.failed']);
    $outbox->enqueue('run.failed', ['run_id' => 'r-2', 'category' => 'run.failed']);

    $result = $outbox->drain();

    expect($result->replayed)->toBe(2);
    expect($result->claimed)->toBe(2);
    expect($result->failed)->toBe(0);
    expect(DB::table('swarm_audit_outbox')->where('status', 'pending')->count())->toBe(0);
    expect($sink->recordsForCategory('run.failed'))->toHaveCount(2);
});

test('DatabaseAuditOutbox drain increments attempts on transient failure and leaves the row pending', function (): void {
    $failingSink = new class implements SwarmAuditSink
    {
        public int $attempts = 0;

        public function emit(string $category, array $payload): void
        {
            $this->attempts++;
            throw new RuntimeException("sink failure #{$this->attempts}");
        }
    };
    app()->instance(SwarmAuditSink::class, $failingSink);
    app()->forgetInstance(AuditOutbox::class);
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-1']);

    $result = $outbox->drain();

    expect($result->replayed)->toBe(0);
    expect($result->failed)->toBe(1);
    $row = DB::table('swarm_audit_outbox')->first();
    expect($row->status)->toBe('pending');
    expect($row->attempts)->toBe(1);
    expect($row->reserved_at)->toBeNull();
    expect($row->last_error)->toContain('sink failure');
});

test('DatabaseAuditOutbox drain moves rows past max_attempts to dead_letter', function (): void {
    config()->set('swarm.audit.outbox.max_attempts', 2);

    $failingSink = new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('permanent sink failure');
        }
    };
    app()->instance(SwarmAuditSink::class, $failingSink);
    app()->forgetInstance(AuditOutbox::class);
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-1']);

    $first = $outbox->drain();
    expect($first->failed)->toBe(1);
    expect($first->deadLettered)->toBe(0);

    $second = $outbox->drain();
    expect($second->replayed)->toBe(0);
    expect($second->failed)->toBe(0);
    expect($second->deadLettered)->toBe(1);

    $row = DB::table('swarm_audit_outbox')->first();
    expect($row->status)->toBe('dead_letter');
    expect($row->attempts)->toBe(2);
});

test('DatabaseAuditOutbox seals payload column at rest when encrypt_at_rest is enabled', function (): void {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(AuditOutbox::class);
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-sealed', 'secret' => 'top-secret-payload']);

    $row = DB::table('swarm_audit_outbox')->first();
    expect($row->payload)->toStartWith('sw0:');
    expect($row->payload)->not->toContain('top-secret-payload');
});

test('DatabaseAuditOutbox drain unseals payload before re-emitting through the bound sink', function (): void {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(AuditOutbox::class);
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-roundtrip', 'category' => 'run.failed', 'secret' => 'value-x']);

    $outbox->drain();

    $records = $sink->recordsForCategory('run.failed');
    expect($records)->toHaveCount(1);
    expect($records[0]['secret'])->toBe('value-x');
});

test('DatabaseAuditOutbox seals last_error column on transient failure when encrypt_at_rest is enabled', function (): void {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $failingSink = new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('sensitive-error-detail');
        }
    };
    app()->instance(SwarmAuditSink::class, $failingSink);
    app()->forgetInstance(AuditOutbox::class);
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-1']);
    $outbox->drain();

    $row = DB::table('swarm_audit_outbox')->first();
    expect($row->last_error)->toStartWith('sw0:');
    expect($row->last_error)->not->toContain('sensitive-error-detail');
});

test('DatabaseAuditOutbox emits Log::error at the moment of dead_letter transition', function (): void {
    config()->set('swarm.audit.outbox.max_attempts', 1);
    Log::spy();

    $failingSink = new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('permanent sink rejection');
        }
    };
    app()->instance(SwarmAuditSink::class, $failingSink);
    app()->forgetInstance(AuditOutbox::class);
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-dead']);
    $outbox->drain();

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'dead_letter')
                && ($context['run_id'] ?? null) === 'r-dead'
                && ($context['category'] ?? null) === 'run.failed'
                && ($context['attempts'] ?? null) === 1;
        })
        ->once();
});

test('DatabaseAuditOutbox drain reclaims stale reservations after the reservation timeout', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(AuditOutbox::class);
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-stale']);

    // Simulate a relay worker that claimed the row then crashed before
    // completing the drain. The next drain should reclaim and re-emit.
    DB::table('swarm_audit_outbox')
        ->where('run_id', 'r-stale')
        ->update(['reserved_at' => now()->subMinutes(5)]);

    $result = $outbox->drain();

    expect($result->replayed)->toBe(1);
    expect($result->reclaimed)->toBe(1);
    expect($result->claimed)->toBe(1);
    expect(DB::table('swarm_audit_outbox')->where('run_id', 'r-stale')->count())->toBe(0);
});

test('DatabaseAuditOutbox drain leaves fresh reservations alone (does not reclaim within the reservation timeout)', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(AuditOutbox::class);
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-fresh']);

    // Fresh reservation, well within the default 60s reservation timeout.
    DB::table('swarm_audit_outbox')
        ->where('run_id', 'r-fresh')
        ->update(['reserved_at' => now()->subSeconds(5)]);

    $result = $outbox->drain();

    expect($result->replayed)->toBe(0);
    expect($result->claimed)->toBe(0);
    expect(DB::table('swarm_audit_outbox')->where('run_id', 'r-fresh')->where('status', 'pending')->count())->toBe(1);
});

test('DatabaseAuditOutbox drain claims a bounded batch and leaves the rest pending for a second worker', function (): void {
    // SQLite does not honor FOR UPDATE SKIP LOCKED (the production locking
    // mechanism that lets concurrent relay workers each claim a disjoint
    // batch). This test exercises the bounded-claim path: with limit=2 and
    // three pending rows, a single drain reserves exactly 2 and leaves the
    // third for the next worker — proving the claim region honors the limit.
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(AuditOutbox::class);
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-a']);
    $outbox->enqueue('run.failed', ['run_id' => 'r-b']);
    $outbox->enqueue('run.failed', ['run_id' => 'r-c']);

    $first = $outbox->drain(2);

    expect($first->claimed)->toBe(2);
    expect($first->replayed)->toBe(2);
    expect(DB::table('swarm_audit_outbox')->where('status', 'pending')->count())->toBe(1);

    $second = $outbox->drain(2);
    expect($second->claimed)->toBe(1);
    expect($second->replayed)->toBe(1);
    expect(DB::table('swarm_audit_outbox')->count())->toBe(0);
});

test('DatabaseAuditOutbox preserves the original signer key across replay (signer rotation guard)', function (): void {
    // Signer that stamps payloads with whichever key is currently bound. If the
    // outbox re-signed on replay, the recorded sink would receive a payload
    // stamped with the rotated key. The contract (UPGRADING + audit-evidence-
    // contract.md) guarantees this does NOT happen: outbox stores the signed
    // bytes from the original emit attempt, and re-emit ships those bytes as-is.
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

    // First emit: signed under K1, but the bound sink rejects it so the
    // dispatcher routes the K1-signed envelope to the outbox.
    app()->instance(SwarmAuditSigner::class, $signer);
    app()->instance(SwarmAuditSink::class, new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('sink rejects fresh emits');
        }
    });
    config()->set('swarm.audit.failure_policy', 'queue');
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.failed', ['run_id' => 'r-rotate']);

    // Rotate the signer key and swap to a recording sink so replay results
    // are observable.
    $keyHolder->currentKey = 'K2';
    $recordingSink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $recordingSink);
    app()->forgetInstance(AuditOutbox::class);

    app(AuditOutbox::class)->drain();

    $replayed = $recordingSink->recordsForCategory('run.failed');
    expect($replayed)->toHaveCount(1);
    expect($replayed[0]['signature_key'])->toBe('K1');
});

test('DatabaseAuditOutbox preserves the original signature_key_id across replay (key rotation)', function (): void {
    // Twin of the signature_key test above, for the dispatcher-stamped
    // signature_key_id field. The signer reports whichever key is currently
    // bound as BOTH the signature and its keyId(). The dispatcher stamps
    // signature_key_id from keyId() at the original (K1) emit; the outbox stores
    // the K1-stamped envelope and replays it verbatim without re-signing or
    // re-stamping. Rotating to K2 before drain must NOT change the stored id —
    // the record names the key that produced its ORIGINAL signature.
    $keyHolder = new class
    {
        public string $currentKey = 'K1';
    };
    $signer = new class($keyHolder) implements SwarmAuditSigner
    {
        public function __construct(private readonly object $keyHolder) {}

        public function sign(string $category, array $payload): array
        {
            $payload['signature'] = 'sig-under-'.$this->keyHolder->currentKey;
            $payload['signature_algorithm'] = 'sha256';

            return $payload;
        }

        public function keyId(): ?string
        {
            return $this->keyHolder->currentKey;
        }
    };

    // First emit: signed + stamped under K1, but the bound sink rejects it so
    // the dispatcher routes the K1-stamped envelope to the outbox.
    app()->instance(SwarmAuditSigner::class, $signer);
    app()->instance(SwarmAuditSink::class, new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('sink rejects fresh emits');
        }
    });
    config()->set('swarm.audit.failure_policy', 'queue');
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.failed', ['run_id' => 'r-rotate-keyid']);

    // Rotate the signer key and swap to a recording sink so replay is observable.
    $keyHolder->currentKey = 'K2';
    $recordingSink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $recordingSink);
    app()->forgetInstance(AuditOutbox::class);

    app(AuditOutbox::class)->drain();

    $replayed = $recordingSink->recordsForCategory('run.failed');
    expect($replayed)->toHaveCount(1);
    expect($replayed[0]['signature_key_id'])->toBe('K1');
    expect($replayed[0]['signature'])->toBe('sig-under-K1');
});

test('DatabaseAuditOutbox drain skips dead_letter rows', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(AuditOutbox::class);
    $outbox = app(AuditOutbox::class);

    $outbox->enqueue('run.failed', ['run_id' => 'r-1'], deadLetter: true);
    $outbox->enqueue('run.failed', ['run_id' => 'r-2']);

    $result = $outbox->drain();

    expect($result->replayed)->toBe(1);
    expect($result->claimed)->toBe(1);
    expect(DB::table('swarm_audit_outbox')->where('status', 'dead_letter')->count())->toBe(1);
});
