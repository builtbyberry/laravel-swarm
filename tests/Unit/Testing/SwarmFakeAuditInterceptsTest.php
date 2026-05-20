<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Testing\Audit\RecordingCapturePolicy;
use BuiltByBerry\LaravelSwarm\Testing\Audit\RecordingSinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Testing\Audit\RecordingSwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use PHPUnit\Framework\AssertionFailedError;

// -- RecordingCapturePolicy ---------------------------------------------------

test('RecordingCapturePolicy returns Full for every category when no delegate is supplied', function (): void {
    $recorder = new RecordingCapturePolicy;

    expect($recorder->inputs())->toBe(CaptureDecision::Full);
    expect($recorder->outputs())->toBe(CaptureDecision::Full);
    expect($recorder->artifacts())->toBe(CaptureDecision::Full);
    expect($recorder->activeContext())->toBe(CaptureDecision::Full);

    expect($recorder->records())->toHaveCount(4);
});

test('RecordingCapturePolicy forwards to the delegate when one is supplied', function (): void {
    $delegate = new class implements CapturePolicy
    {
        public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Redact;
        }

        public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Skip;
        }

        public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Redact;
        }

        public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }
    };

    $recorder = new RecordingCapturePolicy($delegate);

    expect($recorder->inputs())->toBe(CaptureDecision::Redact);
    expect($recorder->outputs())->toBe(CaptureDecision::Skip);
    expect($recorder->artifacts())->toBe(CaptureDecision::Redact);
    expect($recorder->activeContext())->toBe(CaptureDecision::Full);

    $recorder->assertCapturedDecision('inputs', CaptureDecision::Redact);
    $recorder->assertCapturedDecision('outputs', CaptureDecision::Skip);
});

test('RecordingCapturePolicy records context and actor', function (): void {
    $recorder = new RecordingCapturePolicy;
    $context = RunContext::fromTask('hi');
    $actor = new Actor(id: 'u-1', type: 'user');

    $recorder->inputs($context, $actor);

    $records = $recorder->recordsFor('inputs');
    expect($records)->toHaveCount(1);
    expect($records[0]['context'])->toBe($context);
    expect($records[0]['actor'])->toBe($actor);
});

test('assertCaptured fails with a clear message when the category was never invoked', function (): void {
    $recorder = new RecordingCapturePolicy;

    expect(fn () => $recorder->assertCaptured('outputs'))
        ->toThrow(AssertionFailedError::class, 'CapturePolicy was not invoked for category [outputs].');
});

test('assertNeverCaptured fails when the category was invoked', function (): void {
    $recorder = new RecordingCapturePolicy;
    $recorder->inputs();

    expect(fn () => $recorder->assertNeverCaptured('inputs'))
        ->toThrow(AssertionFailedError::class, 'CapturePolicy was invoked for category [inputs] unexpectedly.');
});

test('assertCapturedWith matches across categories', function (): void {
    $recorder = new RecordingCapturePolicy;
    $actor = new Actor(id: 'u-7', type: 'user');
    $recorder->outputs(actor: $actor);

    $recorder->assertCapturedWith(fn (array $record): bool => ($record['actor']?->id ?? null) === 'u-7');
});

// -- RecordingSinkFailureHandler ---------------------------------------------

test('RecordingSinkFailureHandler returns Swallow by default', function (): void {
    $recorder = new RecordingSinkFailureHandler;
    $sink = new RecordingSwarmAuditSink;

    $decision = $recorder->handle($sink, 'run.started', ['run_id' => 'x'], new RuntimeException('sink down'));

    expect($decision)->toBe(SinkFailureDecision::Swallow);
    expect($recorder->records())->toHaveCount(1);
});

test('RecordingSinkFailureHandler can return a custom default decision', function (): void {
    $recorder = new RecordingSinkFailureHandler(defaultDecision: SinkFailureDecision::Halt);

    $decision = $recorder->handle(new RecordingSwarmAuditSink, 'run.started', [], new RuntimeException('x'));

    expect($decision)->toBe(SinkFailureDecision::Halt);
});

test('RecordingSinkFailureHandler forwards to the delegate when supplied', function (): void {
    $delegate = new class implements SinkFailureHandler
    {
        public function handle(SwarmAuditSink $sink, string $category, array $payload, Throwable $exception): SinkFailureDecision
        {
            return SinkFailureDecision::DeadLetter;
        }
    };

    $recorder = new RecordingSinkFailureHandler($delegate);

    $decision = $recorder->handle(new RecordingSwarmAuditSink, 'run.failed', [], new RuntimeException('x'));

    expect($decision)->toBe(SinkFailureDecision::DeadLetter);
    $recorder->assertSinkFailureRoutedAs(SinkFailureDecision::DeadLetter);
});

test('assertSinkFailureRouted fails when the handler was never invoked', function (): void {
    $recorder = new RecordingSinkFailureHandler;

    expect(fn () => $recorder->assertSinkFailureRouted())
        ->toThrow(AssertionFailedError::class, 'SinkFailureHandler was not invoked.');
});

test('assertNeverSinkFailure fails when the handler was invoked', function (): void {
    $recorder = new RecordingSinkFailureHandler;
    $recorder->handle(new RecordingSwarmAuditSink, 'run.started', [], new RuntimeException('x'));

    expect(fn () => $recorder->assertNeverSinkFailure())
        ->toThrow(AssertionFailedError::class, 'SinkFailureHandler was invoked unexpectedly.');
});

// -- RecordingSwarmAuditSigner -----------------------------------------------

test('RecordingSwarmAuditSigner returns the payload unchanged when no delegate is supplied', function (): void {
    $recorder = new RecordingSwarmAuditSigner;

    $signed = $recorder->sign('run.started', ['run_id' => 'r-1']);

    expect($signed)->toBe(['run_id' => 'r-1']);
    expect($recorder->records())->toHaveCount(1);
});

test('RecordingSwarmAuditSigner forwards to the delegate when supplied', function (): void {
    $delegate = new class implements SwarmAuditSigner
    {
        public function sign(string $category, array $payload): array
        {
            $payload['signature'] = 'sig-of-'.$category;

            return $payload;
        }
    };

    $recorder = new RecordingSwarmAuditSigner($delegate);

    $signed = $recorder->sign('run.completed', ['run_id' => 'r-2']);

    expect($signed)->toBe(['run_id' => 'r-2', 'signature' => 'sig-of-run.completed']);

    $recorder->assertSigned('run.completed', fn (array $record): bool => $record['output']['signature'] === 'sig-of-run.completed');
});

test('assertSigned fails when the signer was never invoked', function (): void {
    $recorder = new RecordingSwarmAuditSigner;

    expect(fn () => $recorder->assertSigned())
        ->toThrow(AssertionFailedError::class, 'SwarmAuditSigner was not invoked for any category.');
});

test('assertNeverSigned fails when the signer was invoked', function (): void {
    $recorder = new RecordingSwarmAuditSigner;
    $recorder->sign('run.started', ['x' => 1]);

    expect(fn () => $recorder->assertNeverSigned())
        ->toThrow(AssertionFailedError::class, 'SwarmAuditSigner was invoked unexpectedly.');
});

// -- SwarmFake intercept helpers + real dispatcher integration ---------------

test('SwarmFake::interceptCapturePolicy swaps the container binding to a recorder', function (): void {
    $recorder = SwarmFake::interceptCapturePolicy();

    expect(app(CapturePolicy::class))->toBe($recorder);
});

test('SwarmFake::interceptSinkFailureHandler swaps the container binding to a recorder', function (): void {
    $recorder = SwarmFake::interceptSinkFailureHandler();

    expect(app(SinkFailureHandler::class))->toBe($recorder);
});

test('SwarmFake::interceptSwarmAuditSigner swaps the container binding to a recorder', function (): void {
    $recorder = SwarmFake::interceptSwarmAuditSigner();

    expect(app(SwarmAuditSigner::class))->toBe($recorder);
});

test('intercept helpers flush the dispatcher singleton so it picks up new bindings', function (): void {
    // Prime the singleton with the default bindings.
    $first = app(SwarmAuditDispatcher::class);

    SwarmFake::interceptCapturePolicy();

    $second = app(SwarmAuditDispatcher::class);

    expect($first)->not->toBe($second);
});

test('signer recorder is invoked by the real dispatcher during emit', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);

    $signerRecorder = SwarmFake::interceptSwarmAuditSigner();

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'r-9']);

    $signerRecorder->assertSigned('run.started');
    expect($sink->allRecords())->toHaveCount(1);
});

test('sink failure handler recorder is invoked by the real dispatcher when the sink throws', function (): void {
    $throwingSink = new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('sink exploded');
        }
    };

    app()->instance(SwarmAuditSink::class, $throwingSink);

    $handlerRecorder = SwarmFake::interceptSinkFailureHandler();

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'r-10']);

    $handlerRecorder->assertSinkFailureRouted();
    $handlerRecorder->assertSinkFailureRoutedAs(SinkFailureDecision::Swallow, 'run.started');
});
