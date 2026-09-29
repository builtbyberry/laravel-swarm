<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableCallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\CallbackSlot;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Jobs\DeliverSwarmCallback;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmTerminalContext;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Testing\FakePendingDispatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

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

uses()->beforeEach(fn () => callbacksUseDatabase())->in(__FILE__);

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

    $outbox->settle('run-fail', new SwarmTerminalContext('run-fail', CallbackSlot::Catch, 'App\\Swarms\\S', 'sequential', null, RuntimeException::class, 'boom'));

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

it('dead-letters a callback that keeps failing and never loses it silently', function (): void {
    config()->set('swarm.callbacks.max_attempts', 1);

    $outbox = callbackOutbox();
    $outbox->register('run-dl', CallbackSlot::Then, function (): void {
        throw new RuntimeException('callback failed');
    });
    $outbox->settle('run-dl', new SwarmTerminalContext('run-dl', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-dl')->value('id');

    $outbox->deliver($id);

    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
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
    config()->set('swarm.callbacks.retention_days', 1);

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
    config()->set('swarm.callbacks.retention_days', 1);
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

it('treats an unsupported native approval failure as a catch, once, never a then', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-approval', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);

    $outbox = callbackOutbox();
    $outbox->register('run-approval', CallbackSlot::Then, fn () => null);
    $outbox->register('run-approval', CallbackSlot::Catch, fn () => null);

    $history->fail('run-approval', new UnsupportedNativeApprovalException, 3600);

    expect(callbackTable()->where('run_id', 'run-approval')->where('slot', 'catch')->value('status'))->toBe('pending');
    expect(callbackTable()->where('run_id', 'run-approval')->where('slot', 'then')->exists())->toBeFalse();
});
