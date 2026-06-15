<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\HaltsSwarmExecution;
use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Exceptions\AuditSinkHaltedException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;

class FakeHmacSigner implements SwarmAuditSigner
{
    public int $signCalls = 0;

    public function sign(string $category, array $payload): array
    {
        $this->signCalls++;
        $payload['signature'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $payload['signature_algorithm'] = 'sha256';

        return $payload;
    }
}

class ThrowingSigner implements SwarmAuditSigner
{
    public function sign(string $category, array $payload): array
    {
        throw new RuntimeException('signer key unavailable');
    }
}

class CategoryFilteringSigner implements SwarmAuditSigner
{
    public function sign(string $category, array $payload): array
    {
        if (! str_starts_with($category, 'run.')) {
            return $payload;
        }

        $payload['signature'] = 'signed';
        $payload['signature_algorithm'] = 'sha256';

        return $payload;
    }
}

class SignatureWithoutAlgorithmSigner implements SwarmAuditSigner
{
    public function sign(string $category, array $payload): array
    {
        $payload['signature'] = 'signed';

        return $payload;
    }
}

class CountingSink implements SwarmAuditSink
{
    public int $emits = 0;

    public function emit(string $category, array $payload): void
    {
        $this->emits++;
    }
}

test('dispatcher emits without signing when no signer is bound', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    $record = $sink->allRecords()[0];
    expect($record)->not->toHaveKey('signature');
    expect($record)->not->toHaveKey('signature_algorithm');
});

test('bound signer is invoked before sink emit and its fields reach the sink', function (): void {
    $sink = new RecordingSwarmAuditSink;
    $signer = new FakeHmacSigner;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, $signer);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    expect($signer->signCalls)->toBe(1);

    $record = $sink->allRecords()[0];
    expect($record)->toHaveKey('signature');
    expect($record['signature_algorithm'])->toBe('sha256');
});

test('signer can choose not to sign a category by returning the payload unchanged', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new CategoryFilteringSigner);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    $dispatcher = app(SwarmAuditDispatcher::class);
    $dispatcher->emit('run.started', []);
    $dispatcher->emit('step.started', []);

    $runRecord = $sink->recordsForCategory('run.started')[0];
    $stepRecord = $sink->recordsForCategory('step.started')[0];

    expect($runRecord)->toHaveKey('signature');
    expect($runRecord['signature_algorithm'])->toBe('sha256');
    expect($stepRecord)->not->toHaveKey('signature');
    expect($stepRecord)->not->toHaveKey('signature_algorithm');
});

test('a signature without a signature_algorithm is treated as a signing failure and never reaches the sink', function (): void {
    $sink = new CountingSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new SignatureWithoutAlgorithmSigner);

    $handler = new class implements SinkFailureHandler
    {
        public int $calls = 0;

        public ?Throwable $lastException = null;

        public function handle(SwarmAuditSink $sink, string $category, array $payload, Throwable $exception): SinkFailureDecision
        {
            $this->calls++;
            $this->lastException = $exception;

            return SinkFailureDecision::Swallow;
        }
    };
    app()->instance(SinkFailureHandler::class, $handler);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', []);

    expect($handler->calls)->toBe(1);
    expect($handler->lastException)->toBeInstanceOf(SwarmException::class);
    expect($handler->lastException->getMessage())->toContain('signature_algorithm');
    expect($sink->emits)->toBe(0); // unverifiable record never reached the sink
});

test('a signature without a signature_algorithm halts under a Halt decision', function (): void {
    app()->instance(SwarmAuditSink::class, new NoOpSwarmAuditSink);
    app()->instance(SwarmAuditSigner::class, new SignatureWithoutAlgorithmSigner);

    $handler = new class implements SinkFailureHandler
    {
        public function handle(SwarmAuditSink $sink, string $category, array $payload, Throwable $exception): SinkFailureDecision
        {
            return SinkFailureDecision::Halt;
        }
    };
    app()->instance(SinkFailureHandler::class, $handler);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    try {
        app(SwarmAuditDispatcher::class)->emit('run.failed', []);
        $this->fail('Expected AuditSinkHaltedException.');
    } catch (AuditSinkHaltedException $halt) {
        expect($halt)->toBeInstanceOf(HaltsSwarmExecution::class);
        expect($halt->getPrevious())->toBeInstanceOf(SwarmException::class);
        expect($halt->getPrevious()->getMessage())->toContain('signature_algorithm');
    }
});

test('signing failure routes through SinkFailureHandler and Swallow stops the emit', function (): void {
    $sink = new CountingSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new ThrowingSigner);

    $handler = new class implements SinkFailureHandler
    {
        public int $calls = 0;

        public function handle(SwarmAuditSink $sink, string $category, array $payload, Throwable $exception): SinkFailureDecision
        {
            $this->calls++;

            return SinkFailureDecision::Swallow;
        }
    };
    app()->instance(SinkFailureHandler::class, $handler);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', []);

    expect($handler->calls)->toBe(1);
    expect($sink->emits)->toBe(0);  // signing failed, sink was never reached
});

test('signing failure with Halt decision throws AuditSinkHaltedException', function (): void {
    app()->instance(SwarmAuditSink::class, new NoOpSwarmAuditSink);
    app()->instance(SwarmAuditSigner::class, new ThrowingSigner);

    $handler = new class implements SinkFailureHandler
    {
        public function handle(SwarmAuditSink $sink, string $category, array $payload, Throwable $exception): SinkFailureDecision
        {
            return SinkFailureDecision::Halt;
        }
    };
    app()->instance(SinkFailureHandler::class, $handler);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    try {
        app(SwarmAuditDispatcher::class)->emit('run.failed', []);
        $this->fail('Expected AuditSinkHaltedException.');
    } catch (AuditSinkHaltedException $halt) {
        expect($halt)->toBeInstanceOf(HaltsSwarmExecution::class);
        expect($halt->getPrevious())->toBeInstanceOf(RuntimeException::class);
        expect($halt->getPrevious()->getMessage())->toBe('signer key unavailable');
    }
});

test('signer sees the enriched envelope including schema_version and category', function (): void {
    $captured = [];

    $signer = new class($captured) implements SwarmAuditSigner
    {
        /**
         * @param  array<string, mixed>  $captured
         */
        public function __construct(public array &$captured) {}

        public function sign(string $category, array $payload): array
        {
            $this->captured = $payload;

            return $payload;
        }
    };
    app()->instance(SwarmAuditSink::class, new NoOpSwarmAuditSink);
    app()->instance(SwarmAuditSigner::class, $signer);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    expect($signer->captured)->toHaveKey('schema_version');
    expect($signer->captured)->toHaveKey('category');
    expect($signer->captured['category'])->toBe('run.started');
    expect($signer->captured)->toHaveKey('occurred_at');
    expect($signer->captured['run_id'])->toBe('x');
});
