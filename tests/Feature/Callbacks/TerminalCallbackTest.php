<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableCallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\CallbackSlot;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Jobs\DeliverSwarmCallback;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmTerminalContext;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Testing\FakePendingDispatch;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support\DeserializationProbe;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Native;
use Laravel\SerializableClosure\Serializers\Signed;
use Mockery;

function callbacksUseDatabase(bool $enabled = true): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.callbacks.enabled', $enabled);

    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(CallbackDeliveryOutbox::class);
    app()->forgetInstance(ReadableCallbackDeliveryOutbox::class);
}

function callbackOutbox(): CallbackDeliveryOutbox
{
    return app(CallbackDeliveryOutbox::class);
}

function callbackTable()
{
    return DB::table('swarm_callback_deliveries');
}

// The package test app sets app.key after the framework has read it, so closures are
// unsigned by default. Callbacks are only delivered when signed, so sign them here and
// put the previous signer back afterwards: it is process-global state.
uses()
    ->beforeEach(function (): void {
        $this->previousClosureSigner = Signed::$signer;
        SerializableClosure::setSecretKey('callback-test-signing-key');
        callbacksUseDatabase();
    })
    ->afterEach(function (): void {
        Signed::$signer = $this->previousClosureSigner;
    })
    ->in(__FILE__);

// --- Registration: flag gating ------------------------------------------------

it('throws the exact pre-feature error from queued then/catch when the flag is off', function (): void {
    callbacksUseDatabase(enabled: false);

    $response = new QueuedSwarmResponse(new FakePendingDispatch, 'run-1');

    expect(fn () => $response->then(fn () => null))
        ->toThrow(BadMethodCallException::class, 'Method [then] does not exist on the queued swarm response.');
    expect(fn () => $response->catch(fn () => null))
        ->toThrow(BadMethodCallException::class, 'Method [catch] does not exist on the queued swarm response.');
});

it('throws the exact pre-feature error from durable then/catch when the flag is off', function (): void {
    callbacksUseDatabase(enabled: false);

    $response = new DurableSwarmResponse(new FakePendingDispatch, app(DurableSwarmManager::class), 'run-1');

    expect(fn () => $response->then(fn () => null))
        ->toThrow(BadMethodCallException::class, 'Method [then] does not exist on the durable swarm response.');
    expect(fn () => $response->catch(fn () => null))
        ->toThrow(BadMethodCallException::class, 'Method [catch] does not exist on the durable swarm response.');
});

it('registers a row when a queued then callback is added with the flag on', function (): void {
    (new QueuedSwarmResponse(new FakePendingDispatch, 'run-reg'))->then(fn () => null);

    $row = callbackTable()->where('run_id', 'run-reg')->first();

    expect($row)->not->toBeNull();
    expect($row->slot)->toBe('then');
    expect($row->status)->toBe('registered');
    expect($row->callback)->not->toBeEmpty();
});

it('seals the callback at rest when encryption is enabled', function (): void {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(SwarmPersistenceCipher::class);
    app()->forgetInstance(CallbackDeliveryOutbox::class);

    callbackOutbox()->register('run-sealed', CallbackSlot::Then, fn () => null);

    expect(callbackTable()->where('run_id', 'run-sealed')->value('callback'))
        ->toStartWith(SwarmPersistenceCipher::PREFIX);
});

it('fails closed when a callback is registered under the cache driver', function (): void {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.callbacks.enabled', true);
    app()->forgetInstance(CallbackDeliveryOutbox::class);

    expect(fn () => (new QueuedSwarmResponse(new FakePendingDispatch, 'run-x'))->then(fn () => null))
        ->toThrow(SwarmException::class);
});

it('rejects a non-serializable callback at registration', function (): void {
    $pdo = DB::connection()->getPdo();

    expect(fn () => (new QueuedSwarmResponse(new FakePendingDispatch, 'run-nonser'))
        ->then(function () use ($pdo): void {
            // captures a live PDO handle — cannot be serialized for later delivery
            $pdo->query('select 1');
        }))->toThrow(SwarmException::class);
});

// --- Outbox lifecycle: settle / drain / deliver -------------------------------

it('arms then and drops catch when a run completes, and delivers the then callback', function (): void {
    $outbox = callbackOutbox();
    $outbox->register('run-done', CallbackSlot::Then, function (SwarmTerminalContext $ctx): void {
        cache()->forever('cb:'.$ctx->runId, $ctx->slot->value.':'.($ctx->swarmClass));
    });
    $outbox->register('run-done', CallbackSlot::Catch, fn () => cache()->forever('cb:run-done', 'catch-should-not-run'));

    $outbox->settle('run-done', new SwarmTerminalContext('run-done', CallbackSlot::Then, 'App\\Swarms\\S', 'sequential'));

    // then row is pending; catch row is gone.
    expect(callbackTable()->where('run_id', 'run-done')->where('slot', 'then')->value('status'))->toBe('pending');
    expect(callbackTable()->where('run_id', 'run-done')->where('slot', 'catch')->exists())->toBeFalse();

    $id = (int) callbackTable()->where('run_id', 'run-done')->value('id');
    $outbox->deliver($id);

    expect(cache()->get('cb:run-done'))->toBe('then:App\\Swarms\\S');
    // delivered rows are removed.
    expect(callbackTable()->where('id', $id)->exists())->toBeFalse();
});

it('arms catch and drops then when a run fails', function (): void {
    $outbox = callbackOutbox();
    $outbox->register('run-fail', CallbackSlot::Then, fn () => cache()->forever('cb:run-fail', 'then-should-not-run'));
    $outbox->register('run-fail', CallbackSlot::Catch, function (SwarmTerminalContext $ctx): void {
        cache()->forever('cb:run-fail', 'catch:'.((string) $ctx->exceptionClass));
    });

    $outbox->settle('run-fail', new SwarmTerminalContext('run-fail', CallbackSlot::Catch, 'App\\Swarms\\S', 'sequential', RuntimeException::class, 'boom'));

    expect(callbackTable()->where('run_id', 'run-fail')->where('slot', 'catch')->value('status'))->toBe('pending');
    expect(callbackTable()->where('run_id', 'run-fail')->where('slot', 'then')->exists())->toBeFalse();

    $id = (int) callbackTable()->where('run_id', 'run-fail')->value('id');
    $outbox->deliver($id);

    expect(cache()->get('cb:run-fail'))->toBe('catch:'.RuntimeException::class);
});

it('drains pending rows by dispatching a delivery job carrying only the row id', function (): void {
    Bus::fake();

    $outbox = callbackOutbox();
    $outbox->register('run-drain', CallbackSlot::Then, fn () => null);
    $outbox->settle('run-drain', new SwarmTerminalContext('run-drain', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-drain')->value('id');

    $result = $outbox->drain();

    expect($result->dispatched)->toBe(1);
    Bus::assertDispatched(DeliverSwarmCallback::class, fn (DeliverSwarmCallback $job): bool => $job->id === $id);
    // reservation is set so a concurrent drain will not re-claim it.
    expect(callbackTable()->where('id', $id)->value('reserved_at'))->not->toBeNull();
});

it('is idempotent: a duplicated terminal settle arms the callback at most once', function (): void {
    $outbox = callbackOutbox();
    $outbox->register('run-dup', CallbackSlot::Then, fn () => null);

    $context = new SwarmTerminalContext('run-dup', CallbackSlot::Then, 'App\\Swarms\\S');
    $outbox->settle('run-dup', $context);
    $outbox->settle('run-dup', $context);

    expect(callbackTable()->where('run_id', 'run-dup')->where('status', 'pending')->count())->toBe(1);
});

it('discards every callback for a run without delivering any', function (): void {
    $outbox = callbackOutbox();
    $outbox->register('run-cancel', CallbackSlot::Then, fn () => null);
    $outbox->register('run-cancel', CallbackSlot::Catch, fn () => null);

    $outbox->discard('run-cancel');

    expect(callbackTable()->where('run_id', 'run-cancel')->exists())->toBeFalse();
});

it('releases a transiently failing callback for retry without incrementing attempts in deliver', function (): void {
    Bus::fake();
    config()->set('swarm.callbacks.max_attempts', 3);

    $outbox = callbackOutbox();
    $outbox->register('run-retry', CallbackSlot::Then, function (): void {
        throw new RuntimeException('transient downstream failure');
    });
    $outbox->settle('run-retry', new SwarmTerminalContext('run-retry', CallbackSlot::Then, 'App\\Swarms\\S'));

    // drain counts the attempt at claim time and dispatches.
    $outbox->drain();
    $id = (int) callbackTable()->where('run_id', 'run-retry')->value('id');
    expect((int) callbackTable()->where('id', $id)->value('attempts'))->toBe(1);

    // deliver runs the throwing closure and RELEASES the reservation (no dead-letter,
    // no attempt bump — the next claim owns the increment), so the row stays retryable.
    $outbox->deliver($id);

    $row = callbackTable()->where('id', $id)->first();
    expect($row->status)->toBe('pending');
    expect((int) $row->attempts)->toBe(1);
    expect($row->reserved_at)->toBeNull();
    expect($row->last_error)->not->toBeNull();
});

it('dead-letters a callback at claim once it exhausts max attempts, and never loses it silently', function (): void {
    Bus::fake();
    config()->set('swarm.callbacks.max_attempts', 2);

    // A row that has already been claimed max_attempts times (e.g. a delivery that kept
    // dying mid-flight): the next claim pushes it over the cap and dead-letters it
    // instead of re-dispatching forever.
    callbackTable()->insert([
        'run_id' => 'run-dl',
        'slot' => 'then',
        'callback' => 'x',
        'context' => null,
        'attempts' => 2,
        'status' => 'pending',
        'last_error' => null,
        'last_attempted_at' => null,
        'reserved_at' => null,
        'created_at' => Carbon::now('UTC'),
        'updated_at' => Carbon::now('UTC'),
    ]);
    $id = (int) callbackTable()->where('run_id', 'run-dl')->value('id');

    $result = $outbox = callbackOutbox()->drain();

    expect($result->deadLettered)->toBe(1);
    expect($result->dispatched)->toBe(0);
    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    Bus::assertNotDispatched(DeliverSwarmCallback::class);
});

it('dead-letters and never invokes a callback whose stored payload is not a valid signed closure', function (): void {
    $outbox = callbackOutbox();
    $outbox->register('run-tamper', CallbackSlot::Then, fn () => cache()->forever('cb:run-tamper', 'invoked'));
    $outbox->settle('run-tamper', new SwarmTerminalContext('run-tamper', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-tamper')->value('id');

    // Corrupt the sealed payload so it can never unseal into a valid signed closure.
    callbackTable()->where('id', $id)->update([
        'callback' => app(SwarmPersistenceCipher::class)->seal('not-a-serialized-closure'),
    ]);

    $outbox->deliver($id);

    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(cache()->has('cb:run-tamper'))->toBeFalse();
});

// --- Deserialization boundary: nothing is built ahead of the signature --------

/**
 * Arm a pending delivery row, then overwrite its stored callback bytes.
 */
function pendingCallbackRowWithPayload(string $runId, string $payload): int
{
    $outbox = callbackOutbox();
    $outbox->register($runId, CallbackSlot::Then, fn () => cache()->forever('cb:'.$runId, 'invoked'));
    $outbox->settle($runId, new SwarmTerminalContext($runId, CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', $runId)->value('id');

    callbackTable()->where('id', $id)->update([
        'callback' => app(SwarmPersistenceCipher::class)->seal($payload),
    ]);

    return $id;
}

function callbackDeadLetterReason(int $id): ?string
{
    return app(SwarmPersistenceCipher::class)->open((string) callbackTable()->where('id', $id)->value('last_error'));
}

/**
 * Wrap a serialized closure body in the SerializableClosure envelope by hand.
 */
function closureEnvelopeAround(string $serializedBody): string
{
    return sprintf('O:%d:"%s":1:{s:12:"serializable";%s}', strlen(SerializableClosure::class), SerializableClosure::class, $serializedBody);
}

it('never constructs a stored object that is not a closure, and dead-letters the row', function (): void {
    DeserializationProbe::reset();
    $id = pendingCallbackRowWithPayload('run-inject', DeserializationProbe::wire());

    callbackOutbox()->deliver($id);

    expect(DeserializationProbe::$woken)->toBe(0);
    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(callbackDeadLetterReason($id))->toBe('callback payload is not a serialized closure');
    expect(cache()->has('cb:run-inject'))->toBeFalse();
});

it('never constructs an object smuggled inside the closure envelope', function (): void {
    DeserializationProbe::reset();
    $id = pendingCallbackRowWithPayload('run-inject-nested', closureEnvelopeAround(DeserializationProbe::wire()));

    callbackOutbox()->deliver($id);

    expect(DeserializationProbe::$woken)->toBe(0);
    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(callbackDeadLetterReason($id))->toBe('callback signature verification failed (payload tampering or APP_KEY rotation)');
});

it('never runs an unsigned closure body smuggled past the signature', function (): void {
    DeserializationProbe::reset();
    $code = '\\'.DeserializationProbe::class.'::execute() ?? fn () => null';
    $unsignedBody = sprintf(
        'O:%d:"%s":5:{s:3:"use";a:0:{}s:8:"function";s:%d:"%s";s:5:"scope";N;s:4:"this";N;s:4:"self";s:32:"%s";}',
        strlen(Native::class),
        Native::class,
        strlen($code),
        $code,
        str_repeat('0', 32),
    );
    $id = pendingCallbackRowWithPayload('run-inject-unsigned', closureEnvelopeAround($unsignedBody));

    callbackOutbox()->deliver($id);

    expect(DeserializationProbe::$executed)->toBe(0);
    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(callbackDeadLetterReason($id))->toBe('callback signature verification failed (payload tampering or APP_KEY rotation)');
});

it('still delivers a signed callback that captures an object', function (): void {
    $captured = new ArrayObject(['value' => 'captured-object']);
    $outbox = callbackOutbox();
    $outbox->register('run-captured', CallbackSlot::Then, function () use ($captured): void {
        cache()->forever('cb:run-captured', $captured['value']);
    });
    $outbox->settle('run-captured', new SwarmTerminalContext('run-captured', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-captured')->value('id');

    $outbox->deliver($id);

    expect(cache()->get('cb:run-captured'))->toBe('captured-object');
    expect(callbackTable()->where('id', $id)->exists())->toBeFalse();
});

it('dead-letters and never invokes an unsigned callback registered without an application key', function (): void {
    // No signer for the life of this test; afterEach restores the previous one.
    SerializableClosure::setSecretKey(null);

    $outbox = callbackOutbox();
    $outbox->register('run-keyless', CallbackSlot::Then, fn () => cache()->forever('cb:run-keyless', 'invoked'));
    $outbox->settle('run-keyless', new SwarmTerminalContext('run-keyless', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-keyless')->value('id');

    $outbox->deliver($id);

    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(callbackDeadLetterReason($id))->toContain('no APP_KEY signing key is configured');
    expect(cache()->has('cb:run-keyless'))->toBeFalse();
});

it('names the missing signing key when a signed callback reaches a process without one', function (): void {
    $outbox = callbackOutbox();
    $outbox->register('run-keyless-worker', CallbackSlot::Then, fn () => cache()->forever('cb:run-keyless-worker', 'invoked'));
    $outbox->settle('run-keyless-worker', new SwarmTerminalContext('run-keyless-worker', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-keyless-worker')->value('id');

    // The delivering process has no signer; afterEach restores the previous one.
    SerializableClosure::setSecretKey(null);
    $outbox->deliver($id);

    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(callbackDeadLetterReason($id))->toContain('no APP_KEY signing key is configured');
    expect(cache()->has('cb:run-keyless-worker'))->toBeFalse();
});

// --- Terminal seam: history store flips callbacks atomically ------------------

it('flips then to pending when the history store records completion', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-seam-done', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);

    $outbox = callbackOutbox();
    $outbox->register('run-seam-done', CallbackSlot::Then, fn () => null);
    $outbox->register('run-seam-done', CallbackSlot::Catch, fn () => null);

    $history->complete('run-seam-done', new SwarmResponse('out'), 3600);

    expect(callbackTable()->where('run_id', 'run-seam-done')->where('slot', 'then')->value('status'))->toBe('pending');
    expect(callbackTable()->where('run_id', 'run-seam-done')->where('slot', 'catch')->exists())->toBeFalse();
});

it('flips catch to pending when the history store records failure, capturing the exception class', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-seam-fail', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);

    $outbox = callbackOutbox();
    $outbox->register('run-seam-fail', CallbackSlot::Then, fn () => null);
    $outbox->register('run-seam-fail', CallbackSlot::Catch, fn () => null);

    $history->fail('run-seam-fail', new RuntimeException('kaput'), 3600);

    expect(callbackTable()->where('run_id', 'run-seam-fail')->where('slot', 'catch')->value('status'))->toBe('pending');
    expect(callbackTable()->where('run_id', 'run-seam-fail')->where('slot', 'then')->exists())->toBeFalse();
});

// --- Relay lane ---------------------------------------------------------------

it('arms catch when a run fails through failWithMetadata (the parallel-stream terminal writer)', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-fwm', 'App\\Swarms\\SeamSwarm', 'parallel', RunContext::fromTask('in'), [], 3600);

    $outbox = callbackOutbox();
    $outbox->register('run-fwm', CallbackSlot::Then, fn () => null);
    $outbox->register('run-fwm', CallbackSlot::Catch, fn () => null);

    // failWithMetadata is the terminal `failed` writer used by ParallelStreamRunner;
    // it must arm catch like fail() does, or a run terminating here loses its callback.
    $history->failWithMetadata('run-fwm', new RuntimeException('branch failed'), ['branch' => 'b1'], 3600);

    expect(callbackTable()->where('run_id', 'run-fwm')->where('slot', 'catch')->value('status'))->toBe('pending');
    expect(callbackTable()->where('run_id', 'run-fwm')->where('slot', 'then')->exists())->toBeFalse();
});

it('arms catch when a run fails through recordPreflightFailure (in-worker preflight)', function (): void {
    // A non-deterministic preflight check can pass at dispatch (so the callback was
    // registered) but fail in the worker, terminating via recordPreflightFailure. That
    // terminal `failed` write must arm catch too.
    $outbox = callbackOutbox();
    $outbox->register('run-preflight', CallbackSlot::Then, fn () => null);
    $outbox->register('run-preflight', CallbackSlot::Catch, fn () => null);

    app(RunHistoryStore::class)->recordPreflightFailure(
        'run-preflight',
        'App\\Swarms\\SeamSwarm',
        'sequential',
        RunContext::fromTask('in'),
        [],
        new RuntimeException('input guardrail blocked in worker'),
        3600,
    );

    expect(callbackTable()->where('run_id', 'run-preflight')->where('slot', 'catch')->value('status'))->toBe('pending');
    expect(callbackTable()->where('run_id', 'run-preflight')->where('slot', 'then')->exists())->toBeFalse();
});

it('delivers pending callbacks through the swarm:relay callback lane', function (): void {
    Bus::fake();

    $outbox = callbackOutbox();
    $outbox->register('run-relay', CallbackSlot::Then, fn () => null);
    $outbox->settle('run-relay', new SwarmTerminalContext('run-relay', CallbackSlot::Then, 'App\\Swarms\\S'));

    $exit = Artisan::call('swarm:relay', ['--type' => ['callback']]);

    expect($exit)->toBe(0);
    Bus::assertDispatched(DeliverSwarmCallback::class);
});

// --- Prune --------------------------------------------------------------------

it('prunes dead-lettered callbacks past the retention window, keeping fresh ones', function (): void {
    config()->set('swarm.callbacks.dead_letter_retention_days', 1);

    $insert = function (string $runId, string $status, ?Carbon $lastAttempt): void {
        callbackTable()->insert([
            'run_id' => $runId,
            'slot' => 'then',
            'callback' => 'x',
            'context' => null,
            'attempts' => 3,
            'status' => $status,
            'last_error' => null,
            'last_attempted_at' => $lastAttempt,
            'reserved_at' => null,
            'created_at' => Carbon::now('UTC'),
            'updated_at' => Carbon::now('UTC'),
        ]);
    };

    $insert('run-old-dl', 'dead_letter', Carbon::now('UTC')->subDays(2));
    $insert('run-fresh-dl', 'dead_letter', Carbon::now('UTC')->subHours(1));

    Artisan::call('swarm:prune');

    expect(callbackTable()->where('run_id', 'run-old-dl')->exists())->toBeFalse();
    expect(callbackTable()->where('run_id', 'run-fresh-dl')->exists())->toBeTrue();
});

it('does not prune when swarm.retention.prevent_prune is set', function (): void {
    config()->set('swarm.callbacks.dead_letter_retention_days', 1);
    config()->set('swarm.retention.prevent_prune', true);

    callbackTable()->insert([
        'run_id' => 'run-protected',
        'slot' => 'then',
        'callback' => 'x',
        'context' => null,
        'attempts' => 3,
        'status' => 'dead_letter',
        'last_error' => null,
        'last_attempted_at' => Carbon::now('UTC')->subDays(5),
        'reserved_at' => null,
        'created_at' => Carbon::now('UTC'),
        'updated_at' => Carbon::now('UTC'),
    ]);

    Artisan::call('swarm:prune');

    expect(callbackTable()->where('run_id', 'run-protected')->exists())->toBeTrue();
});

it('treats an unsupported native approval failure as a catch, exactly once, never a then', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-approval', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);

    $outbox = callbackOutbox();
    $outbox->register('run-approval', CallbackSlot::Then, fn () => cache()->forever('approval', 'then-ran'));
    $outbox->register('run-approval', CallbackSlot::Catch, fn () => cache()->forever('approval', 'catch-ran'));

    $history->fail('run-approval', new UnsupportedNativeApprovalException, 3600);

    // Exactly one pending row (the catch); the then row is gone.
    expect(callbackTable()->where('run_id', 'run-approval')->where('status', 'pending')->count())->toBe(1);
    expect(callbackTable()->where('run_id', 'run-approval')->where('slot', 'catch')->value('status'))->toBe('pending');
    expect(callbackTable()->where('run_id', 'run-approval')->where('slot', 'then')->exists())->toBeFalse();

    // Deliver it: the catch fires once with the settled exception class; then never runs.
    $id = (int) callbackTable()->where('run_id', 'run-approval')->value('id');
    $outbox->deliver($id);
    expect(cache()->get('approval'))->toBe('catch-ran');
});

// --- End-to-end through a real queued run -------------------------------------

it('fires a then callback end-to-end for a real queued run that completes through the runner', function (): void {
    Bus::fake(); // neutralize the PendingDispatch destruct re-dispatch; we run the job by hand
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    $queued = FakeSequentialSwarm::make()
        ->queue('queued-task')
        ->then(function (SwarmTerminalContext $ctx): void {
            cache()->forever('e2e', $ctx->slot->value.':'.$ctx->swarmClass);
        });

    // Drive the real InvokeSwarm job through the actual runner: it executes the swarm,
    // reaches historyStore->complete(), and settles the then callback to pending.
    $queued->getJob()->handle(app(SwarmRunner::class));

    $row = callbackTable()->where('run_id', $queued->runId)->where('slot', 'then')->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe('pending');

    callbackOutbox()->deliver((int) $row->id);

    expect(cache()->get('e2e'))->toStartWith('then:');
    expect(cache()->get('e2e'))->toContain('FakeSequentialSwarm');
});

// --- Kill switch, seams, and delivery edge cases ------------------------------

it('stops draining and delivering already-registered callbacks when the flag is turned off', function (): void {
    $outbox = callbackOutbox();
    $outbox->register('run-kill', CallbackSlot::Then, fn () => cache()->forever('kill', 'ran'));
    $outbox->settle('run-kill', new SwarmTerminalContext('run-kill', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-kill')->value('id');

    // Operator flips the kill switch off mid-flight.
    config()->set('swarm.callbacks.enabled', false);

    expect(callbackOutbox()->drain()->dispatched)->toBe(0);
    callbackOutbox()->deliver($id);

    expect(cache()->has('kill'))->toBeFalse();
    // The row is untouched, ready to resume if the operator re-enables.
    expect(callbackTable()->where('id', $id)->value('status'))->toBe('pending');
});

it('discards both callbacks through the history store cancellation seam', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-cancel-seam', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);

    $outbox = callbackOutbox();
    $outbox->register('run-cancel-seam', CallbackSlot::Then, fn () => null);
    $outbox->register('run-cancel-seam', CallbackSlot::Catch, fn () => null);

    $history->syncDurableState('run-cancel-seam', 'cancelled', RunContext::fromTask('in'), [], 3600, true);

    expect(callbackTable()->where('run_id', 'run-cancel-seam')->exists())->toBeFalse();
});

it('delivers the terminal context built from the history row to the callback', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-ctx', 'App\\Swarms\\SeamSwarm', 'hierarchical', RunContext::fromTask('in'), [], 3600);

    $outbox = callbackOutbox();
    $outbox->register('run-ctx', CallbackSlot::Then, function (SwarmTerminalContext $ctx): void {
        cache()->forever('ctx', $ctx->swarmClass.'|'.((string) $ctx->topology));
    });

    $history->complete('run-ctx', new SwarmResponse('out'), 3600);
    $outbox->deliver((int) callbackTable()->where('run_id', 'run-ctx')->value('id'));

    expect(cache()->get('ctx'))->toBe('App\\Swarms\\SeamSwarm|hierarchical');
});

it('rolls back the callback flip when the terminal write loses its lease', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-lease', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);

    $outbox = callbackOutbox();
    $outbox->register('run-lease', CallbackSlot::Catch, fn () => null);

    // A stale execution token: the lease-guarded update matches no rows and throws,
    // rolling back the whole transaction — including the callback settle.
    expect(fn () => $history->fail('run-lease', new RuntimeException('x'), 3600, 'stale-token', 60))
        ->toThrow(LostSwarmLeaseException::class);

    // The catch row is still 'registered' — the flip did not commit.
    expect(callbackTable()->where('run_id', 'run-lease')->where('slot', 'catch')->value('status'))->toBe('registered');
});

it('falls back to an unknown swarm class when the stored context is unreadable', function (): void {
    $outbox = callbackOutbox();
    $outbox->register('run-fb', CallbackSlot::Then, function (SwarmTerminalContext $ctx): void {
        cache()->forever('fb', $ctx->swarmClass.'|'.$ctx->runId);
    });
    $outbox->settle('run-fb', new SwarmTerminalContext('run-fb', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-fb')->value('id');

    callbackTable()->where('id', $id)->update(['context' => null]);
    $outbox->deliver($id);

    expect(cache()->get('fb'))->toBe('unknown|run-fb');
});

it('is idempotent on repeated delivery of the same row', function (): void {
    cache()->forever('idem', 0);

    $outbox = callbackOutbox();
    $outbox->register('run-idem', CallbackSlot::Then, function (): void {
        cache()->increment('idem');
    });
    $outbox->settle('run-idem', new SwarmTerminalContext('run-idem', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-idem')->value('id');

    $outbox->deliver($id); // invokes, deletes
    $outbox->deliver($id); // row gone -> no-op, does not re-invoke

    expect((int) cache()->get('idem'))->toBe(1);
    expect(callbackTable()->where('id', $id)->exists())->toBeFalse();
});

it('leaves the reservation in place on a transient dispatch failure', function (): void {
    $throwingBus = Mockery::mock(BusDispatcher::class);
    $throwingBus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue driver down'));

    $outbox = new DatabaseCallbackDeliveryOutbox(
        DB::connection(),
        config(),
        app(SwarmPersistenceCipher::class),
        $throwingBus,
    );

    callbackTable()->insert([
        'run_id' => 'run-dispatchfail', 'slot' => 'then', 'callback' => 'x', 'context' => null,
        'attempts' => 0, 'status' => 'pending', 'last_error' => null, 'last_attempted_at' => null,
        'reserved_at' => null, 'created_at' => Carbon::now('UTC'), 'updated_at' => Carbon::now('UTC'),
    ]);
    $id = (int) callbackTable()->where('run_id', 'run-dispatchfail')->value('id');

    $result = $outbox->drain();

    expect($result->failed)->toBe(1);
    expect($result->dispatched)->toBe(0);
    // Reservation left in place (re-claimable after the timeout), not released.
    expect(callbackTable()->where('id', $id)->value('reserved_at'))->not->toBeNull();
    expect(callbackTable()->where('id', $id)->value('status'))->toBe('pending');
});

it('reports callback delivery health as ok when enabled with no failures and warning on dead-letters', function (): void {
    // ok: enabled, no rows.
    Artisan::call('swarm:health', ['--json' => true]);
    $ok = collect(json_decode(Artisan::output(), true)['checks'] ?? [])->firstWhere('component', 'Callback delivery');
    expect($ok['status'])->toBe('ok');

    // warning: a dead-lettered row present.
    callbackTable()->insert([
        'run_id' => 'run-health', 'slot' => 'then', 'callback' => 'x', 'context' => null,
        'attempts' => 5, 'status' => 'dead_letter', 'last_error' => null, 'last_attempted_at' => Carbon::now('UTC'),
        'reserved_at' => null, 'created_at' => Carbon::now('UTC'), 'updated_at' => Carbon::now('UTC'),
    ]);
    Artisan::call('swarm:health', ['--json' => true]);
    $warn = collect(json_decode(Artisan::output(), true)['checks'] ?? [])->firstWhere('component', 'Callback delivery');
    expect($warn['status'])->toBe('warning');
});
