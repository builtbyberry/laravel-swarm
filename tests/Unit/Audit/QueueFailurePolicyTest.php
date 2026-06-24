<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\BooleanCapturePolicy;
use BuiltByBerry\LaravelSwarm\Audit\ConfiguredSinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Audit\NoOpAuditOutbox;
use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
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

class ThrowingEnqueueAuditOutbox implements AuditOutbox
{
    public function __construct(private Throwable $toThrow) {}

    public function enqueue(string $category, array $payload, bool $deadLetter = false): void
    {
        throw $this->toThrow;
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

/**
 * Build a {@see SwarmAuditDispatcher} whose capture posture redacts failure
 * messages (capture inputs/outputs off, redaction left at its default) so the
 * routeToOutbox() log embeds are exercised deterministically. The sink always
 * fails and the configured queue policy drives routing into the outbox.
 */
function makeDispatcherForOutboxRedaction(AuditOutbox $outbox, Throwable $sinkFailure): SwarmAuditDispatcher
{
    $config = new ConfigRepository([
        'swarm' => [
            'audit' => ['failure_policy' => 'queue'],
            'capture' => ['inputs' => false, 'outputs' => false],
        ],
    ]);

    $capture = new SwarmCapture($config, new BooleanCapturePolicy($config));

    return new SwarmAuditDispatcher(
        sink: new class($sinkFailure) implements SwarmAuditSink
        {
            public function __construct(private Throwable $toThrow) {}

            public function emit(string $category, array $payload): void
            {
                throw $this->toThrow;
            }
        },
        config: $config,
        failureHandler: new ConfiguredSinkFailureHandler($config, new NullLogger, $capture),
        capture: $capture,
        signer: null,
        outbox: $outbox,
        logger: Log::getFacadeRoot(),
    );
}

test('ConfiguredSinkFailureHandler returns Queue for queue policy', function (): void {
    $handler = new ConfiguredSinkFailureHandler(
        new ConfigRepository(['swarm' => ['audit' => ['failure_policy' => 'queue']]]),
        new NullLogger,
        app(SwarmCapture::class),
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
        app(SwarmCapture::class),
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

test('routeToOutbox redacts the exception message but preserves the class when the outbox is unavailable', function (): void {
    Log::shouldReceive('warning')->once()->withArgs(
        fn ($message, $context): bool => str_contains($message, 'outbox is unavailable')
            && $context['category'] === 'run.failed'
            && $context['decision'] === 'queue'
            && $context['exception'] === '[redacted]'
            && $context['class'] === RuntimeException::class
            && ! str_contains($context['exception'], 'secret sink fragment')
    );

    $dispatcher = makeDispatcherForOutboxRedaction(
        new NoOpAuditOutbox,
        new RuntimeException('secret sink fragment'),
    );

    // Must not throw — the failure is logged and swallowed.
    $dispatcher->emit('run.failed', ['run_id' => 'r-unavailable']);
});

test('routeToOutbox redacts both exception messages independently when the outbox enqueue fails', function (): void {
    Log::shouldReceive('error')->once()->withArgs(
        fn ($message, $context): bool => str_contains($message, 'outbox enqueue failed')
            && $context['category'] === 'run.failed'
            && $context['decision'] === 'queue'
            && $context['original_exception'] === '[redacted]'
            && $context['original_class'] === RuntimeException::class
            && $context['outbox_exception'] === '[redacted]'
            && $context['outbox_class'] === LogicException::class
            && ! str_contains($context['original_exception'], 'secret sink fragment')
            && ! str_contains($context['outbox_exception'], 'secret outbox fragment')
    );

    $dispatcher = makeDispatcherForOutboxRedaction(
        new ThrowingEnqueueAuditOutbox(new LogicException('secret outbox fragment')),
        new RuntimeException('secret sink fragment'),
    );

    // Must not throw — the original sink failure is preserved and swallowed.
    $dispatcher->emit('run.failed', ['run_id' => 'r-enqueue-fail']);
});
