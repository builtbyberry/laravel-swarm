<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\ConfiguredSinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseAuditOutbox;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * A logger whose every call throws — stands in for a misconfigured or hostile
 * logging stack. AbstractLogger routes all level methods through log(), so both
 * ->log() and ->error() explode.
 */
class HostileLogger extends AbstractLogger
{
    public function log($level, $message, array $context = []): void
    {
        throw new RuntimeException('hostile logger exploded');
    }
}

/** Tiny consumer that exposes the protected trait methods for direct testing. */
function safeReportingSubject(): object
{
    return new class
    {
        use SafeReporting;

        public function report(Throwable $exception): void
        {
            $this->safeReport($exception);
        }

        public function log(LoggerInterface $logger): void
        {
            $this->safeLog($logger, 'error', 'msg', ['k' => 'v']);
        }
    };
}

// ---------------------------------------------------------------------------
// Trait, in isolation
// ---------------------------------------------------------------------------

test('safeLog swallows a throwing logger', function (): void {
    $subject = safeReportingSubject();

    expect(fn () => $subject->log(new HostileLogger))->not->toThrow(Throwable::class);
});

test('safeReport swallows a throwing report() handler', function (): void {
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->andThrow(new RuntimeException('handler exploded'));
    app()->instance(ExceptionHandler::class, $handler);

    $subject = safeReportingSubject();

    expect(fn () => $subject->report(new RuntimeException('original')))->not->toThrow(Throwable::class);
});

// ---------------------------------------------------------------------------
// ConfiguredSinkFailureHandler — degrade-safe even with a hostile logger
// ---------------------------------------------------------------------------

test('failure handler does not propagate a throwing logger (log policy still Swallows)', function (): void {
    $handler = new ConfiguredSinkFailureHandler(
        new ConfigRepository(['swarm' => ['audit' => ['failure_policy' => 'log']]]),
        new HostileLogger,
    );

    $decision = null;
    expect(function () use ($handler, &$decision): void {
        $decision = $handler->handle(new NoOpSwarmAuditSink, 'run.failed', [], new RuntimeException('x'));
    })->not->toThrow(Throwable::class);

    expect($decision)->toBe(SinkFailureDecision::Swallow);
});

test('halt policy still returns Halt even when the logger throws', function (): void {
    $handler = new ConfiguredSinkFailureHandler(
        new ConfigRepository(['swarm' => ['audit' => ['failure_policy' => 'halt']]]),
        new HostileLogger,
    );

    expect($handler->handle(new NoOpSwarmAuditSink, 'run.failed', [], new RuntimeException('x')))
        ->toBe(SinkFailureDecision::Halt);
});

// ---------------------------------------------------------------------------
// DatabaseAuditOutbox — the real dead-letter path survives a hostile logger
// ---------------------------------------------------------------------------

test('audit outbox drain still dead-letters when both the sink and the logger throw', function (): void {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.audit.outbox.max_attempts', 1);

    // A logger that explodes on the dead_letter transition log...
    app()->instance(LoggerInterface::class, new HostileLogger);
    // ...and a sink that always rejects, forcing the row to dead_letter.
    app()->instance(SwarmAuditSink::class, new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('permanent sink rejection');
        }
    });
    app()->forgetInstance(AuditOutbox::class);

    /** @var DatabaseAuditOutbox $outbox */
    $outbox = app()->make(DatabaseAuditOutbox::class);
    $outbox->enqueue('run.failed', ['run_id' => 'r-dead']);

    $result = null;
    expect(function () use ($outbox, &$result): void {
        $result = $outbox->drain();
    })->not->toThrow(Throwable::class);

    expect($result->deadLettered)->toBe(1);
    expect(DB::table('swarm_audit_outbox')->where('status', 'dead_letter')->count())->toBe(1);
});
