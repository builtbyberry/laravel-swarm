<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\HaltsSwarmExecution;
use BuiltByBerry\LaravelSwarm\Contracts\IdentifiesSigningKey;
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

test('a signature with an empty signature_algorithm is rejected like a missing one', function (): void {
    $sink = new CountingSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new class implements SwarmAuditSigner
    {
        public function sign(string $category, array $payload): array
        {
            $payload['signature'] = 'signed';
            $payload['signature_algorithm'] = '';

            return $payload;
        }
    });

    $handler = new class implements SinkFailureHandler
    {
        public ?Throwable $lastException = null;

        public function handle(SwarmAuditSink $sink, string $category, array $payload, Throwable $exception): SinkFailureDecision
        {
            $this->lastException = $exception;

            return SinkFailureDecision::Swallow;
        }
    };
    app()->instance(SinkFailureHandler::class, $handler);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', []);

    expect($handler->lastException)->toBeInstanceOf(SwarmException::class);
    expect($sink->emits)->toBe(0); // empty algorithm is not a valid algorithm name
});

test('an empty signature is treated as unsigned and reaches the sink untouched', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new class implements SwarmAuditSigner
    {
        public function sign(string $category, array $payload): array
        {
            // No real signature produced — an empty string is not "signed",
            // so the algorithm-name guard must not fire and the record flows
            // to the sink as an unsigned payload.
            $payload['signature'] = '';

            return $payload;
        }
    });
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', []);

    $record = $sink->allRecords()[0];
    expect($record['signature'])->toBe('');
    expect($record)->not->toHaveKey('signature_algorithm');
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

test('a legacy signer without keyId() produces no signature_key_id field', function (): void {
    // FakeHmacSigner declares only sign() — it predates keyId(). The dispatcher
    // reads keyId() defensively, so the signed record carries no key id.
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new FakeHmacSigner);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    $record = $sink->allRecords()[0];
    expect($record)->toHaveKey('signature');
    expect($record)->not->toHaveKey('signature_key_id');
});

test('a signer with a non-empty keyId() stamps signature_key_id on signed records', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new class implements IdentifiesSigningKey, SwarmAuditSigner
    {
        public function sign(string $category, array $payload): array
        {
            $payload['signature'] = 'signed';
            $payload['signature_algorithm'] = 'sha256';

            return $payload;
        }

        public function keyId(): ?string
        {
            return 'hmac-2026-07';
        }
    });
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    $record = $sink->allRecords()[0];
    expect($record['signature_key_id'])->toBe('hmac-2026-07');
});

test('a throwing keyId() does not demote an already-verifiable record', function (): void {
    // A signer whose keyId() throws (e.g. a transient keystore/KMS lookup)
    // already produced a verifiable signature. Key-id resolution is a
    // non-authoritative hint, so a throw must be treated as ABSENT — the signed
    // record still reaches the sink, just without signature_key_id, rather than
    // becoming a signing failure that swallows/halts/dead-letters the evidence.
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new class implements IdentifiesSigningKey, SwarmAuditSigner
    {
        public function sign(string $category, array $payload): array
        {
            $payload['signature'] = 'signed';
            $payload['signature_algorithm'] = 'sha256';

            return $payload;
        }

        public function keyId(): ?string
        {
            throw new RuntimeException('keystore lookup failed');
        }
    });
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    $records = $sink->allRecords();
    expect($records)->toHaveCount(1);
    expect($records[0])->toHaveKey('signature'); // record was NOT demoted
    expect($records[0])->not->toHaveKey('signature_key_id');
});

test('a signer-supplied signature_key_id is dropped when the signer does not identify a key', function (): void {
    // signature_key_id is dispatcher-owned. A plain SwarmAuditSigner that tries
    // to set it in sign() must not leak that value: the dispatcher unsets any
    // signer-supplied field before stamping, and this signer exposes no keyId(),
    // so the field must be absent.
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new class implements SwarmAuditSigner
    {
        public function sign(string $category, array $payload): array
        {
            $payload['signature'] = 'signed';
            $payload['signature_algorithm'] = 'sha256';
            $payload['signature_key_id'] = 'signer-set-stray'; // reserved — must be dropped

            return $payload;
        }
    });
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    $record = $sink->allRecords()[0];
    expect($record)->not->toHaveKey('signature_key_id');
});

test('a dispatcher-owned signature_key_id overrides any signer-supplied value', function (): void {
    // When the signer both sets a stray signature_key_id AND identifies a key,
    // the dispatcher-stamped value wins (the stray is unset first).
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new class implements IdentifiesSigningKey, SwarmAuditSigner
    {
        public function sign(string $category, array $payload): array
        {
            $payload['signature'] = 'signed';
            $payload['signature_algorithm'] = 'sha256';
            $payload['signature_key_id'] = 'signer-set-stray';

            return $payload;
        }

        public function keyId(): ?string
        {
            return 'dispatcher-owned-id';
        }
    });
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    $record = $sink->allRecords()[0];
    expect($record['signature_key_id'])->toBe('dispatcher-owned-id');
});

test('no signer bound produces no signature_key_id field', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    $record = $sink->allRecords()[0];
    expect($record)->not->toHaveKey('signature_key_id');
});

test('a signer whose keyId() returns null or empty leaves the field absent', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new class implements IdentifiesSigningKey, SwarmAuditSigner
    {
        public function sign(string $category, array $payload): array
        {
            $payload['signature'] = 'signed';
            $payload['signature_algorithm'] = 'sha256';

            return $payload;
        }

        public function keyId(): ?string
        {
            return null;
        }
    });
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    $record = $sink->allRecords()[0];
    expect($record)->toHaveKey('signature');
    expect($record)->not->toHaveKey('signature_key_id');
});

test('keyId() is not stamped when the signer opts out (no signature)', function (): void {
    // A signer that returns the payload unchanged for a category — the opt-out
    // path — is unsigned, so keyId() must never produce a stray signature_key_id.
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SwarmAuditSigner::class, new class implements IdentifiesSigningKey, SwarmAuditSigner
    {
        public function sign(string $category, array $payload): array
        {
            return $payload; // opts out of every category
        }

        public function keyId(): ?string
        {
            return 'hmac-2026-07';
        }
    });
    app()->forgetInstance(SwarmAuditDispatcher::class);

    app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    $record = $sink->allRecords()[0];
    expect($record)->not->toHaveKey('signature');
    expect($record)->not->toHaveKey('signature_key_id');
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
