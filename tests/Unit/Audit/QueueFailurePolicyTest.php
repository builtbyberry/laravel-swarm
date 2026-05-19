<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\ConfiguredSinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Audit\NoOpAuditOutbox;
use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;

class AlwaysFailingSinkForQueuePolicy implements SwarmAuditSink
{
    public function emit(string $category, array $payload): void
    {
        throw new RuntimeException('sink failure');
    }
}

class CapturingAuditOutbox implements AuditOutbox
{
    public array $enqueued = [];

    public function enqueue(string $category, array $payload, bool $deadLetter = false): void
    {
        $this->enqueued[] = compact('category', 'payload', 'deadLetter');
    }

    public function drain(int $limit = 100): AuditDrainResult
    {
        return new AuditDrainResult(0, 0, 0, 0, 0);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function assertReady(): void {}
}

test('ConfiguredSinkFailureHandler returns Queue for queue policy', function (): void {
    $handler = new ConfiguredSinkFailureHandler(
        new ConfigRepository(['swarm' => ['audit' => ['failure_policy' => 'queue']]]),
        new NullLogger,
    );

    $decision = $handler->handle(
        new AlwaysFailingSinkForQueuePolicy,
        'run.failed',
        ['run_id' => 'r-1'],
        new RuntimeException('sink failure'),
    );

    expect($decision)->toBe(SinkFailureDecision::Queue);
});

test('ConfiguredSinkFailureHandler returns DeadLetter for dead_letter policy', function (): void {
    $handler = new ConfiguredSinkFailureHandler(
        new ConfigRepository(['swarm' => ['audit' => ['failure_policy' => 'dead_letter']]]),
        new NullLogger,
    );

    $decision = $handler->handle(
        new AlwaysFailingSinkForQueuePolicy,
        'run.failed',
        ['run_id' => 'r-1'],
        new RuntimeException('sink failure'),
    );

    expect($decision)->toBe(SinkFailureDecision::DeadLetter);
});

test('default config failure_policy is queue', function (): void {
    expect(config('swarm.audit.failure_policy'))->toBe('queue');
});

test('dispatcher routes Queue decision to the bound audit outbox', function (): void {
    $outbox = new CapturingAuditOutbox;
    app()->instance(SwarmAuditSink::class, new AlwaysFailingSinkForQueuePolicy);
    app()->instance(AuditOutbox::class, $outbox);
    config()->set('swarm.audit.failure_policy', 'queue');

    app()->forgetInstance(SwarmAuditDispatcher::class);
    $dispatcher = app(SwarmAuditDispatcher::class);

    $dispatcher->emit('run.failed', ['run_id' => 'r-1']);

    expect($outbox->enqueued)->toHaveCount(1);
    expect($outbox->enqueued[0]['category'])->toBe('run.failed');
    expect($outbox->enqueued[0]['deadLetter'])->toBeFalse();
});

test('dispatcher routes DeadLetter decision to the bound audit outbox with deadLetter=true', function (): void {
    $outbox = new CapturingAuditOutbox;
    app()->instance(SwarmAuditSink::class, new AlwaysFailingSinkForQueuePolicy);
    app()->instance(AuditOutbox::class, $outbox);
    config()->set('swarm.audit.failure_policy', 'dead_letter');

    app()->forgetInstance(SwarmAuditDispatcher::class);
    $dispatcher = app(SwarmAuditDispatcher::class);

    $dispatcher->emit('run.failed', ['run_id' => 'r-1']);

    expect($outbox->enqueued)->toHaveCount(1);
    expect($outbox->enqueued[0]['deadLetter'])->toBeTrue();
});

test('dispatcher degrades to log+swallow when outbox is unavailable', function (): void {
    app()->instance(SwarmAuditSink::class, new AlwaysFailingSinkForQueuePolicy);
    app()->instance(AuditOutbox::class, new NoOpAuditOutbox);
    config()->set('swarm.audit.failure_policy', 'queue');

    Log::spy();
    app()->forgetInstance(SwarmAuditDispatcher::class);
    $dispatcher = app(SwarmAuditDispatcher::class);

    // Must not throw.
    $dispatcher->emit('run.failed', ['run_id' => 'r-1']);

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

test('end-to-end: database driver writes failed records to swarm_audit_outbox via dispatcher', function (): void {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.audit.failure_policy', 'queue');
    app()->instance(SwarmAuditSink::class, new AlwaysFailingSinkForQueuePolicy);
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    $dispatcher = app(SwarmAuditDispatcher::class);
    $dispatcher->emit('run.failed', ['run_id' => 'r-end-to-end']);

    $row = DB::table('swarm_audit_outbox')->where('run_id', 'r-end-to-end')->first();
    expect($row)->not->toBeNull();
    expect($row->category)->toBe('run.failed');
    expect($row->status)->toBe('pending');
});
