<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableCallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Enums\CallbackSlot;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Jobs\DeliverSwarmCallback;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmTerminalContext;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Testing\FakePendingDispatch;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support\DeserializationProbe;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Laravel\SerializableClosure\Exceptions\InvalidSignatureException;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Native;
use Laravel\SerializableClosure\Serializers\Signed;
use Mockery;
use Psr\Log\LoggerInterface;

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

function deliverClaimedCallback(CallbackDeliveryOutbox $outbox, int $id): void
{
    $claimToken = callbackTable()->where('id', $id)->value('claim_token');

    if (! is_string($claimToken) || $claimToken === '') {
        Bus::fake();
        $outbox->drain();
        $claimToken = callbackTable()->where('id', $id)->value('claim_token');
    }

    $outbox->deliver($id, is_string($claimToken) ? $claimToken : 'obsolete-token');
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

it('applies the callback migration idempotently to the configured table name', function (): void {
    $defaultTable = config('swarm.tables.callback_deliveries');
    $customTable = 'test_swarm_callback_deliveries';
    config()->set('swarm.tables.callback_deliveries', $customTable);

    try {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_09_26_000001_create_swarm_callback_deliveries_table.php';

        $migration->up();
        $migration->up();

        expect(Schema::hasTable($customTable))->toBeTrue()
            ->and(Schema::hasColumns($customTable, ['claim_token', 'available_at']))->toBeTrue()
            ->and(Schema::hasTable((string) $defaultTable))->toBeTrue();

        $migration->down();

        expect(Schema::hasTable($customTable))->toBeFalse()
            ->and(Schema::hasTable((string) $defaultTable))->toBeTrue();
    } finally {
        config()->set('swarm.tables.callback_deliveries', $defaultTable);
        Schema::dropIfExists($customTable);
    }
});

it('uses bounded callback index names for a maximal configured table name', function (): void {
    $defaultTable = config('swarm.tables.callback_deliveries');
    $customTable = str_repeat('c', 64);
    config()->set('swarm.tables.callback_deliveries', $customTable);

    try {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_09_26_000001_create_swarm_callback_deliveries_table.php';
        $migration->up();

        $indexes = collect(Schema::getIndexes($customTable));
        $runIndex = $indexes->first(fn (array $index): bool => $index['columns'] === ['run_id']);

        expect($runIndex)->not->toBeNull()
            ->and(strlen((string) $runIndex['name']))->toBeLessThanOrEqual(64)
            ->and($runIndex['name'])->toBe(substr($customTable, 0, 45).'_run_idx');
    } finally {
        config()->set('swarm.tables.callback_deliveries', $defaultTable);
        Schema::dropIfExists($customTable);
    }
});

it('caches callback readiness schema probes per outbox instance', function (): void {
    $schema = Mockery::mock(SchemaBuilder::class);
    $schema->shouldReceive('hasTable')->once()->with('swarm_callback_deliveries')->andReturnTrue();
    $schema->shouldReceive('hasColumns')->once()
        ->with('swarm_callback_deliveries', ['claim_token', 'available_at'])
        ->andReturnTrue();

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);

    $outbox = new DatabaseCallbackDeliveryOutbox(
        $connection,
        config(),
        app(SwarmPersistenceCipher::class),
        app(BusDispatcher::class),
        app(SwarmAuditDispatcher::class),
    );

    expect($outbox->isAvailable())->toBeTrue();
    $outbox->assertReady();
    expect($outbox->isAvailable())->toBeTrue();
});

it('computes callback health with one aggregate query regardless of backlog size', function (): void {
    $now = Carbon::now('UTC');
    $rows = [];

    for ($index = 0; $index < 120; $index++) {
        $rows[] = [
            'run_id' => 'run-health-aggregate-'.$index,
            'slot' => 'then',
            'callback' => 'x',
            'context' => null,
            'attempts' => 0,
            'status' => 'pending',
            'last_error' => null,
            'last_attempted_at' => null,
            'reserved_at' => null,
            'claim_token' => null,
            'available_at' => null,
            'created_at' => $now->copy()->subMinutes(10),
            'updated_at' => $now->copy()->subMinutes(10),
        ];
    }

    foreach (array_chunk($rows, 40) as $chunk) {
        callbackTable()->insert($chunk);
    }

    $outbox = callbackOutbox();
    $outbox->assertReady();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $summary = app(ReadableCallbackDeliveryOutbox::class)->healthSummary();

    expect($queries)->toHaveCount(1)
        ->and($summary['pending'])->toBe(120)
        ->and($summary['aged_eligible'])->toBe(120)
        ->and($summary['oldest_eligible_at'])->not->toBeNull();
});

it('fails readiness and health clearly when the callback table has the old shape', function (): void {
    $defaultTable = config('swarm.tables.callback_deliveries');
    $oldTable = 'test_old_callback_deliveries';
    Schema::create($oldTable, function ($table): void {
        $table->id();
        $table->string('run_id');
    });
    config()->set('swarm.tables.callback_deliveries', $oldTable);
    app()->forgetInstance(CallbackDeliveryOutbox::class);
    app()->forgetInstance(ReadableCallbackDeliveryOutbox::class);

    try {
        expect(fn () => callbackOutbox()->assertReady())
            ->toThrow(SwarmException::class, 'claim_token')
            ->toThrow(SwarmException::class, 'available_at');

        expect(Artisan::call('swarm:health', ['--json' => true]))->toBe(1);
        $check = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])
            ->firstWhere('component', 'Callback delivery');

        expect($check['status'])->toBe('failed')
            ->and($check['details'])->toContain('claim_token', 'available_at');
    } finally {
        config()->set('swarm.tables.callback_deliveries', $defaultTable);
        app()->forgetInstance(CallbackDeliveryOutbox::class);
        app()->forgetInstance(ReadableCallbackDeliveryOutbox::class);
        Schema::dropIfExists($oldTable);
    }
});

// --- Registration: flag gating ------------------------------------------------

it('throws the exact pre-feature then/catch errors when the flag is off', function (string $responseType): void {
    callbacksUseDatabase(enabled: false);

    $response = $responseType === 'queued'
        ? new QueuedSwarmResponse(new FakePendingDispatch, 'run-1')
        : new DurableSwarmResponse(new FakePendingDispatch, app(DurableSwarmManager::class), 'run-1');

    expect(fn () => $response->then(fn () => null))
        ->toThrow(BadMethodCallException::class, "Method [then] does not exist on the {$responseType} swarm response.");
    expect(fn () => $response->catch(fn () => null))
        ->toThrow(BadMethodCallException::class, "Method [catch] does not exist on the {$responseType} swarm response.");
})->with([
    'queued response' => ['queued'],
    'durable response' => ['durable'],
]);

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

it('rejects a queued then/catch registered in a process without a signing key, storing nothing', function (): void {
    // No signer for the life of this test; afterEach restores the previous one.
    SerializableClosure::setSecretKey(null);

    $response = new QueuedSwarmResponse(new FakePendingDispatch, 'run-keyless-register');

    expect(fn () => $response->then(fn () => null))
        ->toThrow(SwarmException::class, 'Terminal workflow callbacks require APP_KEY');
    expect(fn () => $response->catch(fn () => null))
        ->toThrow(SwarmException::class, 'Terminal workflow callbacks require APP_KEY');

    expect(callbackTable()->where('run_id', 'run-keyless-register')->exists())->toBeFalse();
});

it('rejects a direct outbox registration in a process without a signing key, storing nothing', function (): void {
    SerializableClosure::setSecretKey(null);

    expect(fn () => callbackOutbox()->register('run-keyless-direct', CallbackSlot::Then, fn () => null))
        ->toThrow(SwarmException::class, 'Terminal workflow callbacks require APP_KEY');

    expect(callbackTable()->where('run_id', 'run-keyless-direct')->exists())->toBeFalse();
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
    deliverClaimedCallback($outbox, $id);

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
    deliverClaimedCallback($outbox, $id);

    expect(cache()->get('cb:run-fail'))->toBe('catch:'.RuntimeException::class);
});

it('drains pending rows by dispatching a delivery job carrying the row id and claim token', function (): void {
    Bus::fake();

    $outbox = callbackOutbox();
    $outbox->register('run-drain', CallbackSlot::Then, fn () => null);
    $outbox->settle('run-drain', new SwarmTerminalContext('run-drain', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-drain')->value('id');

    $result = $outbox->drain();

    expect($result->dispatched)->toBe(1);
    Bus::assertDispatched(DeliverSwarmCallback::class, fn (DeliverSwarmCallback $job): bool => $job->id === $id
        && $job->claimToken === callbackTable()->where('id', $id)->value('claim_token'));
    // reservation is set so a concurrent drain will not re-claim it.
    expect(callbackTable()->where('id', $id)->value('reserved_at'))->not->toBeNull();
});

it('uses a claim token compare and set so duplicate delivery jobs execute once', function (): void {
    Bus::fake();
    cache()->forever('cb:claim-cas', 0);

    $outbox = callbackOutbox();
    $outbox->register('run-claim-cas', CallbackSlot::Then, function (): void {
        cache()->increment('cb:claim-cas');
    });
    $outbox->settle('run-claim-cas', new SwarmTerminalContext('run-claim-cas', CallbackSlot::Then, 'App\\Swarms\\S'));
    $outbox->drain();

    $job = null;
    Bus::assertDispatched(DeliverSwarmCallback::class, function (DeliverSwarmCallback $dispatched) use (&$job): bool {
        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(DeliverSwarmCallback::class)
        ->and($job->claimToken)->toBeString()->not->toBeEmpty();

    $outbox->deliver($job->id, $job->claimToken);
    $outbox->deliver($job->id, $job->claimToken);

    expect((int) cache()->get('cb:claim-cas'))->toBe(1)
        ->and(callbackTable()->where('run_id', 'run-claim-cas')->exists())->toBeFalse();
});

it('does not count reservations or dispatch failures as delivery attempts', function (): void {
    config()->set('swarm.callbacks.retry_backoff_seconds', 1);
    $throwingBus = Mockery::mock(BusDispatcher::class);
    $throwingBus->shouldReceive('dispatch')->times(3)->andThrow(new RuntimeException('queue driver down'));
    $outbox = new DatabaseCallbackDeliveryOutbox(
        DB::connection(),
        config(),
        app(SwarmPersistenceCipher::class),
        $throwingBus,
        app(SwarmAuditDispatcher::class),
    );

    callbackTable()->insert([
        'run_id' => 'run-attempt-semantics', 'slot' => 'then', 'callback' => 'x', 'context' => null,
        'attempts' => 0, 'status' => 'pending', 'last_error' => null, 'last_attempted_at' => null,
        'reserved_at' => null, 'claim_token' => null, 'available_at' => null,
        'created_at' => Carbon::now('UTC'), 'updated_at' => Carbon::now('UTC'),
    ]);

    try {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $result = $outbox->drain();
            expect($result->failed)->toBe(1);
            Carbon::setTestNow(Carbon::now('UTC')->addSeconds(2));
        }
    } finally {
        Carbon::setTestNow();
    }

    $row = callbackTable()->where('run_id', 'run-attempt-semantics')->first();
    expect((int) $row->attempts)->toBe(0)
        ->and($row->status)->toBe('pending')
        ->and(Carbon::hasTestNow())->toBeFalse();
});

it('routes callback jobs through the configured callback queue', function (): void {
    Bus::fake();
    config()->set('swarm.callbacks.queue.connection', 'redis');
    config()->set('swarm.callbacks.queue.name', 'swarm-callbacks');

    $outbox = callbackOutbox();
    $outbox->register('run-routing', CallbackSlot::Then, fn () => null);
    $outbox->settle('run-routing', new SwarmTerminalContext('run-routing', CallbackSlot::Then, 'App\\Swarms\\S'));
    $outbox->drain();

    Bus::assertDispatched(DeliverSwarmCallback::class, fn (DeliverSwarmCallback $job): bool => $job->connection === 'redis'
        && $job->queue === 'swarm-callbacks');
});

it('applies callback retry backoff during drain until empty', function (): void {
    config()->set('swarm.callbacks.retry_backoff_seconds', 60);
    config()->set('swarm.callbacks.max_attempts', 5);
    cache()->forever('cb:backoff-attempts', 0);

    $outbox = callbackOutbox();
    $outbox->register('run-backoff', CallbackSlot::Then, function (): void {
        cache()->increment('cb:backoff-attempts');
        throw new RuntimeException('retry later');
    });
    $outbox->settle('run-backoff', new SwarmTerminalContext('run-backoff', CallbackSlot::Then, 'App\\Swarms\\S'));

    Artisan::call('swarm:relay', ['--type' => ['callback'], '--drain-until-empty' => true]);

    $row = callbackTable()->where('run_id', 'run-backoff')->first();
    expect((int) cache()->get('cb:backoff-attempts'))->toBe(1)
        ->and((int) $row->attempts)->toBe(1)
        ->and($row->status)->toBe('pending')
        ->and(Carbon::parse((string) $row->available_at)->isFuture())->toBeTrue();
});

it('records callback delivered audit evidence without executable payload data', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);
    app()->forgetInstance(CallbackDeliveryOutbox::class);

    $outbox = callbackOutbox();
    $outbox->register('run-audit-delivered', CallbackSlot::Then, fn () => cache()->forever('sensitive-callback-body', 'ran'));
    $outbox->settle('run-audit-delivered', new SwarmTerminalContext('run-audit-delivered', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-audit-delivered')->value('id');
    deliverClaimedCallback($outbox, $id);

    $record = $sink->recordsForCategory('callback.delivered')[0] ?? [];
    expect($record)->toMatchArray([
        'delivery_id' => $id,
        'run_id' => 'run-audit-delivered',
        'slot' => 'then',
        'attempts' => 1,
    ])->and(array_keys($record))->toEqualCanonicalizing([
        'schema_version', 'category', 'occurred_at', 'delivery_id', 'run_id', 'slot', 'attempts',
    ])->and(json_encode($record))->not->toContain('SerializableClosure', 'sensitive-callback-body');
});

it('keeps callback exception secrets out of dead letter logs', function (): void {
    $secret = 'tenant-secret-token-39284';
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')->once()->with(
        'Swarm terminal callback reached dead_letter status.',
        Mockery::on(fn (array $context): bool => ($context['run_id'] ?? null) === 'run-dead-log'
            && ($context['slot'] ?? null) === 'then'
            && ($context['exception_class'] ?? null) === RuntimeException::class
            && ($context['reason'] ?? null) === 'callback execution failed'
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $secret)
            && array_keys($context) === ['id', 'run_id', 'slot', 'attempts', 'reason', 'exception_class']),
    );
    config()->set('swarm.callbacks.max_attempts', 1);
    $outbox = new DatabaseCallbackDeliveryOutbox(
        DB::connection(),
        config(),
        app(SwarmPersistenceCipher::class),
        app(BusDispatcher::class),
        app(SwarmAuditDispatcher::class),
        $logger,
    );
    $outbox->register('run-dead-log', CallbackSlot::Then, function () use ($secret): void {
        throw new RuntimeException('callback failed with '.$secret);
    });
    $outbox->settle('run-dead-log', new SwarmTerminalContext('run-dead-log', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-dead-log')->value('id');

    deliverClaimedCallback($outbox, $id);

    $row = callbackTable()->where('id', $id)->first();
    expect($row->status)->toBe('dead_letter')
        ->and(app(SwarmPersistenceCipher::class)->open($row->last_error))->toContain($secret);
});

it('reports the original callback exception through the application exception handler', function (): void {
    Exceptions::fake();
    config()->set('swarm.callbacks.max_attempts', 1);

    $outbox = callbackOutbox();
    $outbox->register('run-reported-callback', CallbackSlot::Then, function (): void {
        throw new RuntimeException('application-visible callback failure');
    });
    $outbox->settle('run-reported-callback', new SwarmTerminalContext('run-reported-callback', CallbackSlot::Then, 'App\\Swarms\\S'));

    deliverClaimedCallback($outbox, (int) callbackTable()->where('run_id', 'run-reported-callback')->value('id'));

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'application-visible callback failure');
});

it('does not emit callback delivered audit evidence for stale-token or lost-race deliveries', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);
    app()->forgetInstance(CallbackDeliveryOutbox::class);
    $outbox = callbackOutbox();

    $outbox->register('run-stale-audit', CallbackSlot::Then, fn () => null);
    $outbox->settle('run-stale-audit', new SwarmTerminalContext('run-stale-audit', CallbackSlot::Then, 'App\\Swarms\\S'));
    $staleId = (int) callbackTable()->where('run_id', 'run-stale-audit')->value('id');
    $outbox->deliver($staleId, 'stale-token');

    $outbox->register('run-lost-race-audit', CallbackSlot::Then, function (): void {
        DB::table('swarm_callback_deliveries')
            ->where('run_id', 'run-lost-race-audit')
            ->update(['claim_token' => 'replacement-token']);
    });
    $outbox->settle('run-lost-race-audit', new SwarmTerminalContext('run-lost-race-audit', CallbackSlot::Then, 'App\\Swarms\\S'));
    $lostRaceId = (int) callbackTable()->where('run_id', 'run-lost-race-audit')->value('id');
    deliverClaimedCallback($outbox, $lostRaceId);

    expect($sink->recordsForCategory('callback.delivered'))->toBe([])
        ->and(callbackTable()->where('id', $staleId)->exists())->toBeTrue()
        ->and(callbackTable()->where('id', $lostRaceId)->value('claim_token'))->toBe('replacement-token');
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

it('counts a transient callback failure as one execution attempt and delays its retry', function (): void {
    Bus::fake();
    config()->set('swarm.callbacks.max_attempts', 3);

    $outbox = callbackOutbox();
    $outbox->register('run-retry', CallbackSlot::Then, function (): void {
        throw new RuntimeException('transient downstream failure');
    });
    $outbox->settle('run-retry', new SwarmTerminalContext('run-retry', CallbackSlot::Then, 'App\\Swarms\\S'));

    // Reservation and dispatch are not delivery attempts.
    $outbox->drain();
    $id = (int) callbackTable()->where('run_id', 'run-retry')->value('id');
    expect((int) callbackTable()->where('id', $id)->value('attempts'))->toBe(0);

    // Delivery owns the attempt increment and releases the row with backoff.
    deliverClaimedCallback($outbox, $id);

    $row = callbackTable()->where('id', $id)->first();
    expect($row->status)->toBe('pending');
    expect((int) $row->attempts)->toBe(1);
    expect($row->reserved_at)->toBeNull();
    expect($row->claim_token)->toBeNull();
    expect($row->available_at)->not->toBeNull();
    expect($row->last_error)->not->toBeNull();
});

it('dead-letters a stale in-flight callback after its last execution attempt', function (): void {
    Bus::fake();
    config()->set('swarm.callbacks.max_attempts', 2);

    // A delivery that died after beginning its final callback execution is dead-lettered
    // when its lease expires instead of being dispatched for an extra attempt.
    callbackTable()->insert([
        'run_id' => 'run-dl',
        'slot' => 'then',
        'callback' => 'x',
        'context' => null,
        'attempts' => 2,
        'status' => 'delivering',
        'last_error' => null,
        'last_attempted_at' => null,
        'reserved_at' => Carbon::now('UTC')->subMinutes(5),
        'claim_token' => 'expired-final-attempt',
        'available_at' => null,
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

it('dead-letters eligible pending work already at a newly lowered attempt cap', function (): void {
    Bus::fake();
    config()->set('swarm.callbacks.max_attempts', 2);

    callbackTable()->insert([
        'run_id' => 'run-lowered-cap',
        'slot' => 'then',
        'callback' => 'x',
        'context' => null,
        'attempts' => 3,
        'status' => 'pending',
        'last_error' => null,
        'last_attempted_at' => Carbon::now('UTC')->subMinutes(10),
        'reserved_at' => null,
        'claim_token' => null,
        'available_at' => Carbon::now('UTC')->subMinute(),
        'created_at' => Carbon::now('UTC')->subHour(),
        'updated_at' => Carbon::now('UTC')->subMinute(),
    ]);

    $result = callbackOutbox()->drain();
    $row = callbackTable()->where('run_id', 'run-lowered-cap')->first();

    expect($result->deadLettered)->toBe(1)
        ->and($result->dispatched)->toBe(0)
        ->and($result->reclaimed)->toBe(0)
        ->and($row->status)->toBe('dead_letter')
        ->and((int) $row->attempts)->toBe(3)
        ->and(app(SwarmPersistenceCipher::class)->open($row->last_error))->toContain('configured attempt cap of 2 was reached');
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

    deliverClaimedCallback($outbox, $id);

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

/**
 * A hand-written unsigned closure body whose code calls the probe when it is loaded.
 */
function unsignedClosureBodyRunningProbe(): string
{
    $code = '\\'.DeserializationProbe::class.'::execute() ?? fn () => null';

    return sprintf(
        'O:%d:"%s":5:{s:3:"use";a:0:{}s:8:"function";s:%d:"%s";s:5:"scope";N;s:4:"this";N;s:4:"self";s:32:"%s";}',
        strlen(Native::class),
        Native::class,
        strlen($code),
        $code,
        str_repeat('0', 32),
    );
}

it('never constructs a stored object that is not a closure, and dead-letters the row', function (): void {
    DeserializationProbe::reset();
    $id = pendingCallbackRowWithPayload('run-inject', DeserializationProbe::wire());

    deliverClaimedCallback(callbackOutbox(), $id);

    expect(DeserializationProbe::$woken)->toBe(0);
    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(callbackDeadLetterReason($id))->toBe('callback payload is not a serialized closure');
    expect(cache()->has('cb:run-inject'))->toBeFalse();
});

it('never constructs an object smuggled inside the closure envelope', function (): void {
    Exceptions::fake();
    DeserializationProbe::reset();
    $id = pendingCallbackRowWithPayload('run-inject-nested', closureEnvelopeAround(DeserializationProbe::wire()));

    deliverClaimedCallback(callbackOutbox(), $id);

    expect(DeserializationProbe::$woken)->toBe(0);
    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(callbackDeadLetterReason($id))->toBe('callback signature verification failed (payload tampering or APP_KEY rotation)');
    Exceptions::assertReported(InvalidSignatureException::class);
});

it('never runs an unsigned closure body smuggled past the signature', function (): void {
    DeserializationProbe::reset();
    $id = pendingCallbackRowWithPayload('run-inject-unsigned', closureEnvelopeAround(unsignedClosureBodyRunningProbe()));

    deliverClaimedCallback(callbackOutbox(), $id);

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

    deliverClaimedCallback($outbox, $id);

    expect(cache()->get('cb:run-captured'))->toBe('captured-object');
    expect(callbackTable()->where('id', $id)->exists())->toBeFalse();
});

it('dead-letters and never invokes a callback row stored unsigned when a keyed worker delivers it', function (): void {
    // Registration refuses a keyless process, so build the bytes such a process
    // would have stored (before that guard, or written by something else) by hand.
    $signer = Signed::$signer;
    SerializableClosure::setSecretKey(null);
    $unsigned = serialize(new SerializableClosure(fn () => cache()->forever('cb:run-unsigned', 'invoked')));
    Signed::$signer = $signer;

    expect($unsigned)->toContain(Native::class)->not->toContain(Signed::class);

    $id = pendingCallbackRowWithPayload('run-unsigned', $unsigned);

    deliverClaimedCallback(callbackOutbox(), $id);

    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(callbackDeadLetterReason($id))->toBe('callback signature verification failed (payload tampering or APP_KEY rotation)');
    expect(cache()->has('cb:run-unsigned'))->toBeFalse();
});

it('names the missing signing key when a signed callback reaches a process without one', function (): void {
    $outbox = callbackOutbox();
    $outbox->register('run-keyless-worker', CallbackSlot::Then, fn () => cache()->forever('cb:run-keyless-worker', 'invoked'));
    $outbox->settle('run-keyless-worker', new SwarmTerminalContext('run-keyless-worker', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-keyless-worker')->value('id');

    // The delivering process has no signer; afterEach restores the previous one.
    SerializableClosure::setSecretKey(null);
    deliverClaimedCallback($outbox, $id);

    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(callbackDeadLetterReason($id))->toContain('no APP_KEY signing key is configured');
    expect(cache()->has('cb:run-keyless-worker'))->toBeFalse();
});

it('treats a falsy non-null signer as a missing signing key during delivery', function (): void {
    $outbox = callbackOutbox();
    $outbox->register('run-falsy-signer', CallbackSlot::Then, fn () => cache()->forever('cb:run-falsy-signer', 'invoked'));
    $outbox->settle('run-falsy-signer', new SwarmTerminalContext('run-falsy-signer', CallbackSlot::Then, 'App\\Swarms\\S'));
    $id = (int) callbackTable()->where('run_id', 'run-falsy-signer')->value('id');

    $signer = Signed::$signer;
    Signed::$signer = false;
    try {
        deliverClaimedCallback($outbox, $id);
    } finally {
        Signed::$signer = $signer;
    }

    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(callbackDeadLetterReason($id))->toContain('no APP_KEY signing key is configured');
    expect(cache()->has('cb:run-falsy-signer'))->toBeFalse();
});

it('deserializes nothing at all in a process without a signing key', function (): void {
    DeserializationProbe::reset();
    $id = pendingCallbackRowWithPayload('run-keyless-inject', DeserializationProbe::wire());

    SerializableClosure::setSecretKey(null);
    deliverClaimedCallback(callbackOutbox(), $id);

    expect(DeserializationProbe::$woken)->toBe(0);
    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(callbackDeadLetterReason($id))->toContain('no APP_KEY signing key is configured');
});

it('never runs a forged signed body in a process without a signing key', function (): void {
    DeserializationProbe::reset();
    $inner = unsignedClosureBodyRunningProbe();
    $forged = sprintf(
        'O:%d:"%s":2:{s:12:"serializable";s:%d:"%s";s:4:"hash";s:5:"bogus";}',
        strlen(Signed::class),
        Signed::class,
        strlen($inner),
        $inner,
    );
    $id = pendingCallbackRowWithPayload('run-keyless-forged', closureEnvelopeAround($forged));

    SerializableClosure::setSecretKey(null);
    deliverClaimedCallback(callbackOutbox(), $id);

    expect(DeserializationProbe::$executed)->toBe(0);
    expect(callbackTable()->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(cache()->has('cb:run-keyless-forged'))->toBeFalse();
});

// --- Terminal seam: history store flips callbacks atomically ------------------

it('settles terminal callbacks through every history writer', function (string $writer, string $runId, string $topology, CallbackSlot $slot): void {
    $history = app(RunHistoryStore::class);

    if ($writer !== 'recordPreflightFailure') {
        $history->start($runId, 'App\\Swarms\\SeamSwarm', $topology, RunContext::fromTask('in'), [], 3600);
    }

    $outbox = callbackOutbox();
    $outbox->register($runId, CallbackSlot::Then, fn () => null);
    $outbox->register($runId, CallbackSlot::Catch, fn () => null);

    match ($writer) {
        'complete' => $history->complete($runId, new SwarmResponse('out'), 3600),
        'fail' => $history->fail($runId, new RuntimeException('kaput'), 3600),
        'failWithMetadata' => $history->failWithMetadata($runId, new RuntimeException('branch failed'), ['branch' => 'b1'], 3600),
        'recordPreflightFailure' => $history->recordPreflightFailure(
            $runId,
            'App\\Swarms\\SeamSwarm',
            $topology,
            RunContext::fromTask('in'),
            [],
            new RuntimeException('input guardrail blocked in worker'),
            3600,
        ),
    };

    $other = $slot === CallbackSlot::Then ? CallbackSlot::Catch : CallbackSlot::Then;
    expect(callbackTable()->where('run_id', $runId)->where('slot', $slot->value)->value('status'))->toBe('pending');
    expect(callbackTable()->where('run_id', $runId)->where('slot', $other->value)->exists())->toBeFalse();
})->with([
    'complete' => ['complete', 'run-seam-done', 'sequential', CallbackSlot::Then],
    'fail' => ['fail', 'run-seam-fail', 'sequential', CallbackSlot::Catch],
    'failWithMetadata' => ['failWithMetadata', 'run-fwm', 'parallel', CallbackSlot::Catch],
    'recordPreflightFailure' => ['recordPreflightFailure', 'run-preflight', 'sequential', CallbackSlot::Catch],
]);

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

it('swarm prune removes callbacks before their expired terminal history', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);
    $history = app(RunHistoryStore::class);
    $history->start('run-prune-order', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);
    callbackOutbox()->register('run-prune-order', CallbackSlot::Then, fn () => null);
    $history->complete('run-prune-order', new SwarmResponse('out', context: RunContext::fromTask('in')), 3600);
    DB::table('swarm_run_histories')->where('run_id', 'run-prune-order')->update([
        'expires_at' => Carbon::now('UTC')->subMinute(),
    ]);

    expect(Artisan::call('swarm:prune'))->toBe(0);

    $record = $sink->recordsForCategory('command.prune')[0];
    expect(callbackTable()->where('run_id', 'run-prune-order')->exists())->toBeFalse()
        ->and(DB::table('swarm_run_histories')->where('run_id', 'run-prune-order')->exists())->toBeFalse()
        ->and($record['counts']['history'])->toBe(1)
        ->and($record['counts']['callback_deliveries'])->toBe(1);
});

it('swarm prune preserves live and expired delivering callbacks for relay recovery', function (): void {
    Bus::fake();
    config()->set('swarm.callbacks.reservation_timeout_seconds', 60);
    $history = app(RunHistoryStore::class);
    $outbox = callbackOutbox();

    foreach (['live', 'expired'] as $lease) {
        $runId = 'run-prune-delivering-'.$lease;
        $history->start($runId, 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);
        $outbox->register($runId, CallbackSlot::Then, fn () => null);
        $history->complete($runId, new SwarmResponse('out'), 3600);
        callbackTable()->where('run_id', $runId)->update([
            'status' => 'delivering',
            'attempts' => 1,
            'reserved_at' => $lease === 'live'
                ? Carbon::now('UTC')->subSeconds(10)
                : Carbon::now('UTC')->subSeconds(90),
            'claim_token' => $lease.'-token',
        ]);
        DB::table('swarm_run_histories')->where('run_id', $runId)->update([
            'expires_at' => Carbon::now('UTC')->subMinute(),
        ]);
    }

    expect(Artisan::call('swarm:prune'))->toBe(0)
        ->and(callbackTable()->where('status', 'delivering')->count())->toBe(2)
        ->and(DB::table('swarm_run_histories')->whereIn('run_id', [
            'run-prune-delivering-live',
            'run-prune-delivering-expired',
        ])->exists())->toBeFalse();

    $result = $outbox->drain();
    expect($result->reclaimed)->toBe(1)
        ->and($result->dispatched)->toBe(1)
        ->and(callbackTable()->where('run_id', 'run-prune-delivering-live')->value('status'))->toBe('delivering')
        ->and(callbackTable()->where('run_id', 'run-prune-delivering-expired')->value('status'))->toBe('pending');
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
    deliverClaimedCallback($outbox, $id);
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

    deliverClaimedCallback(callbackOutbox(), (int) $row->id);

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
    deliverClaimedCallback(callbackOutbox(), $id);

    expect(cache()->has('kill'))->toBeFalse();
    // The row is untouched, ready to resume if the operator re-enables.
    expect(callbackTable()->where('id', $id)->value('status'))->toBe('pending');
});

it('settles callbacks while disabled and resumes delivery after re-enable', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-disabled-settle', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);
    $outbox = callbackOutbox();
    $outbox->register('run-disabled-settle', CallbackSlot::Then, fn () => cache()->forever('disabled-settle', 'then'));
    $outbox->register('run-disabled-settle', CallbackSlot::Catch, fn () => cache()->forever('disabled-settle', 'catch'));

    config()->set('swarm.callbacks.enabled', false);
    $history->complete('run-disabled-settle', new SwarmResponse('out'), 3600);

    expect(callbackTable()->where('run_id', 'run-disabled-settle')->where('slot', 'then')->value('status'))->toBe('pending')
        ->and(callbackTable()->where('run_id', 'run-disabled-settle')->where('slot', 'catch')->exists())->toBeFalse()
        ->and(cache()->has('disabled-settle'))->toBeFalse();

    config()->set('swarm.callbacks.enabled', true);
    deliverClaimedCallback($outbox, (int) callbackTable()->where('run_id', 'run-disabled-settle')->value('id'));

    expect(cache()->get('disabled-settle'))->toBe('then');
});

it('settles failure callbacks while disabled and resumes delivery after re-enable', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-disabled-failure', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);
    $outbox = callbackOutbox();
    $outbox->register('run-disabled-failure', CallbackSlot::Then, fn () => cache()->forever('disabled-failure', 'then'));
    $outbox->register('run-disabled-failure', CallbackSlot::Catch, fn () => cache()->forever('disabled-failure', 'catch'));

    config()->set('swarm.callbacks.enabled', false);
    $history->fail('run-disabled-failure', new RuntimeException('failed'), 3600);

    expect(callbackTable()->where('run_id', 'run-disabled-failure')->where('slot', 'catch')->value('status'))->toBe('pending')
        ->and(callbackTable()->where('run_id', 'run-disabled-failure')->where('slot', 'then')->exists())->toBeFalse();

    config()->set('swarm.callbacks.enabled', true);
    deliverClaimedCallback($outbox, (int) callbackTable()->where('run_id', 'run-disabled-failure')->value('id'));
    expect(cache()->get('disabled-failure'))->toBe('catch');
});

it('discards cancellation callbacks while disabled', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-disabled-cancel', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);
    $outbox = callbackOutbox();
    $outbox->register('run-disabled-cancel', CallbackSlot::Then, fn () => null);
    $outbox->register('run-disabled-cancel', CallbackSlot::Catch, fn () => null);

    config()->set('swarm.callbacks.enabled', false);
    $history->syncDurableState('run-disabled-cancel', 'cancelled', RunContext::fromTask('in'), [], 3600, true);

    expect(callbackTable()->where('run_id', 'run-disabled-cancel')->exists())->toBeFalse();
});

it('keeps flag-off callback-free terminal writers on their pre-feature transaction paths', function (string $writer, int $expectedQueries, int $expectedTransactions): void {
    callbacksUseDatabase(enabled: false);
    $history = app(RunHistoryStore::class);
    $runIds = [
        'cold' => 'run-flag-off-cost-cold-'.$writer,
        'warm' => 'run-flag-off-cost-warm-'.$writer,
    ];

    foreach ($runIds as $runId) {
        if ($writer !== 'recordPreflightFailure') {
            $history->start($runId, 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), ['before' => true], 3600);
        }
    }

    $phase = 'cold';
    $queries = ['cold' => [], 'warm' => []];
    $began = ['cold' => 0, 'warm' => 0];
    $committed = ['cold' => 0, 'warm' => 0];
    DB::listen(function (QueryExecuted $query) use (&$phase, &$queries): void {
        $queries[$phase][] = $query->sql;
    });
    Event::listen(TransactionBeginning::class, function () use (&$phase, &$began): void {
        $began[$phase]++;
    });
    Event::listen(TransactionCommitted::class, function () use (&$phase, &$committed): void {
        $committed[$phase]++;
    });

    $write = static function (string $runId) use ($history, $writer): void {
        match ($writer) {
            'complete' => $history->complete($runId, new SwarmResponse('out'), 3600),
            'fail' => $history->fail($runId, new RuntimeException('failed'), 3600),
            'failWithMetadata' => $history->failWithMetadata($runId, new RuntimeException('failed'), ['after' => true], 3600),
            'recordPreflightFailure' => $history->recordPreflightFailure(
                $runId,
                'App\\Swarms\\SeamSwarm',
                'sequential',
                RunContext::fromTask('in'),
                [],
                new RuntimeException('failed'),
                3600,
            ),
            'cancelled' => $history->syncDurableState($runId, 'cancelled', RunContext::fromTask('in'), [], 3600, true),
        };
    };

    $write($runIds['cold']);
    $phase = 'warm';
    $write($runIds['warm']);

    $callbackQueries = collect($queries)->map(
        static fn (array $phaseQueries): array => array_values(array_filter(
            $phaseQueries,
            static fn (string $sql): bool => str_contains($sql, 'swarm_callback_deliveries'),
        )),
    );

    expect($queries['cold'])->toHaveCount($expectedQueries + 1)
        ->and($queries['warm'])->toHaveCount($expectedQueries)
        ->and($callbackQueries['cold'])->toHaveCount(2)
        ->and(strtolower($callbackQueries['cold'][1]))->toContain('exists')
        ->and($callbackQueries['warm'])->toHaveCount(1)
        ->and(strtolower($callbackQueries['warm'][0]))->toContain('exists')
        ->and($began)->toBe(['cold' => $expectedTransactions, 'warm' => $expectedTransactions])
        ->and($committed)->toBe(['cold' => $expectedTransactions, 'warm' => $expectedTransactions]);
})->with([
    'complete' => ['complete', 2, 0],
    'fail' => ['fail', 2, 0],
    'failWithMetadata' => ['failWithMetadata', 3, 1],
    'recordPreflightFailure' => ['recordPreflightFailure', 4, 0],
    'cancelled syncDurableState' => ['cancelled', 2, 0],
]);

it('rolls back a pre-registered callback settlement after the flag is disabled', function (): void {
    $outbox = new class(DB::connection(), config(), app(SwarmPersistenceCipher::class), app(BusDispatcher::class), app(SwarmAuditDispatcher::class)) extends DatabaseCallbackDeliveryOutbox
    {
        public function settle(string $runId, SwarmTerminalContext $context): void
        {
            parent::settle($runId, $context);

            throw new RuntimeException('settlement interrupted');
        }
    };
    $history = new DatabaseRunHistoryStore(
        DB::connection(),
        config(),
        app(SwarmCapture::class),
        app(SwarmPersistenceCipher::class),
        callbacks: $outbox,
    );
    $history->start('run-disabled-atomic', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);
    $outbox->register('run-disabled-atomic', CallbackSlot::Then, fn () => null);
    config()->set('swarm.callbacks.enabled', false);

    expect(fn () => $history->complete('run-disabled-atomic', new SwarmResponse('out'), 3600))
        ->toThrow(RuntimeException::class, 'settlement interrupted');

    expect(DB::table('swarm_run_histories')->where('run_id', 'run-disabled-atomic')->value('status'))->toBe('running')
        ->and(callbackTable()->where('run_id', 'run-disabled-atomic')->value('status'))->toBe('registered');
});

it('keeps database terminal history monotonic after completion', function (): void {
    $history = app(RunHistoryStore::class);
    $history->start('run-monotonic-db', 'App\\Swarms\\SeamSwarm', 'sequential', RunContext::fromTask('in'), [], 3600);
    $outbox = callbackOutbox();
    $outbox->register('run-monotonic-db', CallbackSlot::Then, fn () => null);
    $outbox->register('run-monotonic-db', CallbackSlot::Catch, fn () => null);

    $history->complete('run-monotonic-db', new SwarmResponse('completed-output'), 3600);
    $history->fail('run-monotonic-db', new RuntimeException('late failure'), 3600);

    $record = DB::table('swarm_run_histories')->where('run_id', 'run-monotonic-db')->first();
    expect($record->status)->toBe('completed')
        ->and($record->output)->toBe('completed-output')
        ->and(callbackTable()->where('run_id', 'run-monotonic-db')->where('slot', 'then')->value('status'))->toBe('pending')
        ->and(callbackTable()->where('run_id', 'run-monotonic-db')->where('slot', 'catch')->exists())->toBeFalse();
});

it('keeps every database terminal outcome monotonic across late history writers', function (string $first, string $second): void {
    $runId = 'run-monotonic-'.$first.'-'.$second;
    $history = app(RunHistoryStore::class);
    $history->start($runId, 'App\\Swarms\\SeamSwarm', 'parallel', RunContext::fromTask('in'), ['original' => true], 3600);

    match ($first) {
        'failed' => $history->fail($runId, new RuntimeException('first failure'), 3600),
        'cancelled' => $history->syncDurableState($runId, 'cancelled', RunContext::fromTask('in'), ['original' => true], 3600, true),
        'completed' => $history->complete($runId, new SwarmResponse(
            'first output',
            metadata: ['original' => true],
            context: RunContext::fromTask('in'),
        ), 3600),
    };

    match ($second) {
        'complete' => $history->complete($runId, new SwarmResponse('late output'), 3600),
        'fail' => $history->fail($runId, new RuntimeException('late failure'), 3600),
        'failWithMetadata' => $history->failWithMetadata($runId, new RuntimeException('late metadata failure'), ['late' => true], 3600),
        'recordPreflightFailure' => $history->recordPreflightFailure(
            $runId,
            'App\\Swarms\\Replacement',
            'sequential',
            RunContext::fromTask('late input'),
            ['late' => true],
            new RuntimeException('late preflight failure'),
            3600,
        ),
    };

    $record = $history->find($runId);
    expect($record['status'])->toBe($first)
        ->and($record['metadata'])->not->toHaveKey('late');
})->with([
    'failed to completed' => ['failed', 'complete'],
    'cancelled to failed' => ['cancelled', 'fail'],
    'failWithMetadata on completed' => ['completed', 'failWithMetadata'],
    'recordPreflightFailure on completed' => ['completed', 'recordPreflightFailure'],
]);

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
    deliverClaimedCallback($outbox, (int) callbackTable()->where('run_id', 'run-ctx')->value('id'));

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
    deliverClaimedCallback($outbox, $id);

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

    deliverClaimedCallback($outbox, $id); // invokes, deletes
    deliverClaimedCallback($outbox, $id); // row gone -> no-op, does not re-invoke

    expect((int) cache()->get('idem'))->toBe(1);
    expect(callbackTable()->where('id', $id)->exists())->toBeFalse();
});

it('releases a dispatch failure with retry backoff without counting an attempt', function (): void {
    $throwingBus = Mockery::mock(BusDispatcher::class);
    $throwingBus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue driver down'));

    $outbox = new DatabaseCallbackDeliveryOutbox(
        DB::connection(),
        config(),
        app(SwarmPersistenceCipher::class),
        $throwingBus,
        app(SwarmAuditDispatcher::class),
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
    $row = callbackTable()->where('id', $id)->first();
    expect($row->reserved_at)->toBeNull();
    expect($row->claim_token)->toBeNull();
    expect($row->available_at)->not->toBeNull();
    expect((int) $row->attempts)->toBe(0);
    expect($row->status)->toBe('pending');
});

it('uses callback-specific retry advice for relay dispatch failures', function (): void {
    Exceptions::fake();
    $throwingBus = Mockery::mock(BusDispatcher::class);
    $throwingBus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('callback queue unavailable'));
    $outbox = new DatabaseCallbackDeliveryOutbox(
        DB::connection(),
        config(),
        app(SwarmPersistenceCipher::class),
        $throwingBus,
        app(SwarmAuditDispatcher::class),
    );
    $outbox->register('run-relay-callback-failure', CallbackSlot::Then, fn () => null);
    $outbox->settle('run-relay-callback-failure', new SwarmTerminalContext('run-relay-callback-failure', CallbackSlot::Then, 'App\\Swarms\\S'));
    app()->instance(CallbackDeliveryOutbox::class, $outbox);

    $exit = Artisan::call('swarm:relay', ['--type' => ['callback']]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('callback delivery could not be dispatched due to a transient error')
        ->and($output)->toContain('callback retry delay or delivery lease timeout')
        ->and($output)->not->toContain('will be re-claimed after the reservation timeout');
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
