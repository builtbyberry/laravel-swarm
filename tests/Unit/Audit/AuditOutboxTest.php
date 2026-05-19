<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpAuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseAuditOutbox;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use Illuminate\Support\Facades\DB;

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
