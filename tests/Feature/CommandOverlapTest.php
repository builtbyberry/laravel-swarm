<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\CommandOverlapGuard;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Enums\RelayLane;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;
use BuiltByBerry\LaravelSwarm\Responses\CallbackDrainResult;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;

final class CommandOverlapTestLockStore implements LockProvider, Store
{
    private ArrayStore $store;

    public function __construct(private Lock $lock)
    {
        $this->store = new ArrayStore;
    }

    public function get($key): mixed
    {
        return $this->store->get($key);
    }

    public function many(array $keys): array
    {
        return $this->store->many($keys);
    }

    public function put($key, $value, $seconds): bool
    {
        return $this->store->put($key, $value, $seconds);
    }

    public function putMany(array $values, $seconds): bool
    {
        return $this->store->putMany($values, $seconds);
    }

    public function increment($key, $value = 1): int|bool
    {
        return $this->store->increment($key, $value);
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->store->decrement($key, $value);
    }

    public function forever($key, $value): bool
    {
        return $this->store->forever($key, $value);
    }

    public function touch($key, $seconds): bool
    {
        return $this->store->touch($key, $seconds);
    }

    public function forget($key): bool
    {
        return $this->store->forget($key);
    }

    public function flush(): bool
    {
        return $this->store->flush();
    }

    public function getPrefix(): string
    {
        return $this->store->getPrefix();
    }

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return $this->lock;
    }

    public function restoreLock($name, $owner): Lock
    {
        return $this->lock;
    }
}

function commandOverlapRecordingSink(): RecordingSwarmAuditSink
{
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    return $sink;
}

function holdCommandOverlapLock(string $key): Lock
{
    /** @var CacheManager $cache */
    $cache = app(CacheManager::class);
    $store = $cache->store((string) config('swarm.commands.overlap.store'))->getStore();
    $lock = $store->lock($key, 60);

    expect($lock->get())->toBeTrue();

    return $lock;
}

test('swarm recover contention is observable and never enters the recovery sweep', function (): void {
    $manager = Mockery::mock(DurableSwarmManager::class);
    $manager->shouldNotReceive('recover');
    app()->instance(DurableSwarmManager::class, $manager);

    $sink = commandOverlapRecordingSink();
    $lock = holdCommandOverlapLock(CommandOverlapGuard::RECOVER_KEY);

    try {
        $exit = Artisan::call('swarm:recover');
    } finally {
        $lock->release();
    }
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('holds the command overlap lease')
        ->and($output)->toContain('swarm:health')
        ->and($output)->toContain('--durable')
        ->and($sink->recordsForCategory('command.recover'))->toHaveCount(1)
        ->and($sink->recordsForCategory('command.recover')[0]['status'])->toBe('skipped_overlap');
});

test('swarm relay contention on every lane is observable and never drains any outbox', function (): void {
    $durable = Mockery::mock(DurableOutbox::class);
    $durable->shouldNotReceive('drain');
    app()->instance(DurableOutbox::class, $durable);

    $auditOutbox = Mockery::mock(AuditOutbox::class);
    $auditOutbox->shouldNotReceive('drain');
    app()->instance(AuditOutbox::class, $auditOutbox);

    $callbackOutbox = Mockery::mock(CallbackDeliveryOutbox::class);
    $callbackOutbox->shouldNotReceive('drain');
    app()->instance(CallbackDeliveryOutbox::class, $callbackOutbox);

    $sink = commandOverlapRecordingSink();
    $locks = array_map(
        static fn (RelayLane $lane): Lock => holdCommandOverlapLock($lane->leaseKey()),
        RelayLane::cases(),
    );

    try {
        $exit = Artisan::call('swarm:relay');
    } finally {
        foreach ($locks as $lock) {
            $lock->release();
        }
    }
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain("Every selected lane's command overlap lease is held")
        ->and($output)->toContain('nothing was drained')
        ->and($output)->toContain('contended_lanes')
        ->and($sink->recordsForCategory('command.relay'))->toHaveCount(1)
        ->and($sink->recordsForCategory('command.relay')[0]['status'])->toBe('skipped_overlap')
        ->and($sink->recordsForCategory('command.relay')[0]['attempts'])->toBe(0)
        ->and($sink->recordsForCategory('command.relay')[0]['contended_lanes'])->toBe(['durable', 'audit', 'callback']);
});

test('recovery and relay own distinct command leases', function (): void {
    $durable = Mockery::mock(DurableOutbox::class);
    $durable->shouldReceive('drain')->once()->andReturn(new DrainResult(0, 0, 0, 0, 0));
    app()->instance(DurableOutbox::class, $durable);

    $auditOutbox = Mockery::mock(AuditOutbox::class);
    $auditOutbox->shouldReceive('drain')->once()->andReturn(new AuditDrainResult(0, 0, 0, 0, 0));
    app()->instance(AuditOutbox::class, $auditOutbox);

    $lock = holdCommandOverlapLock(CommandOverlapGuard::RECOVER_KEY);

    try {
        expect(Artisan::call('swarm:relay'))->toBe(0);
    } finally {
        $lock->release();
    }
});

test('process-local array stores fail before a recovery sweep', function (): void {
    config()->set('swarm.commands.overlap.store', 'array');

    $manager = Mockery::mock(DurableSwarmManager::class);
    $manager->shouldNotReceive('recover');
    app()->instance(DurableSwarmManager::class, $manager);

    $sink = commandOverlapRecordingSink();

    expect(fn () => Artisan::call('swarm:recover'))
        ->toThrow(SwarmException::class, 'cannot provide the required command-level cross-process lock');

    expect($sink->recordsForCategory('command.recover'))->toHaveCount(1)
        ->and($sink->recordsForCategory('command.recover')[0]['status'])->toBe('failed');
});

test('null stores fail before a recovery sweep', function (): void {
    config()->set('cache.stores.command-overlap-null', ['driver' => 'null']);
    config()->set('swarm.commands.overlap.store', 'command-overlap-null');

    $manager = Mockery::mock(DurableSwarmManager::class);
    $manager->shouldNotReceive('recover');
    app()->instance(DurableSwarmManager::class, $manager);

    expect(fn () => Artisan::call('swarm:recover'))
        ->toThrow(SwarmException::class, 'cannot provide the required command-level cross-process lock');
});

test('failover stores fail before a recovery sweep', function (): void {
    config()->set('cache.stores.command-overlap-failover', [
        'driver' => 'failover',
        'stores' => ['array'],
    ]);
    config()->set('swarm.commands.overlap.store', 'command-overlap-failover');

    $manager = Mockery::mock(DurableSwarmManager::class);
    $manager->shouldNotReceive('recover');
    app()->instance(DurableSwarmManager::class, $manager);

    expect(fn () => Artisan::call('swarm:recover'))
        ->toThrow(SwarmException::class, 'cannot provide the required command-level cross-process lock');
});

test('non-lock-capable stores fail before a recovery sweep', function (): void {
    /** @var CacheManager $cache */
    $cache = app(CacheManager::class);
    $cache->extend('command-overlap-no-lock', static fn (): Repository => new Repository(
        Mockery::mock(Store::class),
    ));
    config()->set('cache.stores.command-overlap-no-lock', ['driver' => 'command-overlap-no-lock']);
    config()->set('swarm.commands.overlap.store', 'command-overlap-no-lock');

    $manager = Mockery::mock(DurableSwarmManager::class);
    $manager->shouldNotReceive('recover');
    app()->instance(DurableSwarmManager::class, $manager);

    expect(fn () => Artisan::call('swarm:recover'))
        ->toThrow(SwarmException::class, 'does not support atomic locks');
});

test('a non-positive lease fails before a relay sweep', function (): void {
    config()->set('swarm.commands.overlap.lease_seconds', 0);

    $durable = Mockery::mock(DurableOutbox::class);
    $durable->shouldNotReceive('drain');
    app()->instance(DurableOutbox::class, $durable);

    $auditOutbox = Mockery::mock(AuditOutbox::class);
    $auditOutbox->shouldNotReceive('drain');
    app()->instance(AuditOutbox::class, $auditOutbox);

    expect(fn () => Artisan::call('swarm:relay'))
        ->toThrow(SwarmException::class, 'must be greater than zero');
});

test('lock acquisition failures are contextualized and audited before recovery work', function (): void {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andThrow(new RuntimeException('backend unavailable'));

    /** @var CacheManager $cache */
    $cache = app(CacheManager::class);
    $cache->extend('command-overlap-throwing', static fn (): Repository => new Repository(
        new CommandOverlapTestLockStore($lock),
    ));
    config()->set('cache.stores.command-overlap-throwing', ['driver' => 'command-overlap-throwing']);
    config()->set('swarm.commands.overlap.store', 'command-overlap-throwing');

    $manager = Mockery::mock(DurableSwarmManager::class);
    $manager->shouldNotReceive('recover');
    app()->instance(DurableSwarmManager::class, $manager);
    $sink = commandOverlapRecordingSink();

    expect(fn () => Artisan::call('swarm:recover'))
        ->toThrow(SwarmException::class, 'using store [command-overlap-throwing]');

    expect($sink->recordsForCategory('command.recover'))->toHaveCount(1)
        ->and($sink->recordsForCategory('command.recover')[0]['status'])->toBe('failed');
});

test('the recovery lock is released when the sweep throws', function (): void {
    $attempts = 0;
    $manager = Mockery::mock(DurableSwarmManager::class);
    $manager->shouldReceive('recover')
        ->twice()
        ->andReturnUsing(function () use (&$attempts): array {
            $attempts++;

            if ($attempts === 1) {
                throw new RuntimeException('first sweep failed');
            }

            return [];
        });
    app()->instance(DurableSwarmManager::class, $manager);

    expect(fn () => Artisan::call('swarm:recover'))
        ->toThrow(RuntimeException::class, 'first sweep failed');

    expect(Artisan::call('swarm:recover'))->toBe(0);
});

// ---------------------------------------------------------------------------
// Per-lane relay leases
// ---------------------------------------------------------------------------

/**
 * A lock-capable store whose locks come from an in-process ArrayStore, except for
 * named keys that get a scripted lock — used to make one lane's acquire or
 * release throw while the other lanes behave normally.
 */
final class CommandOverlapNamedLockStore implements LockProvider, Store
{
    private ArrayStore $store;

    /**
     * @param  array<string, Lock>  $overrides
     */
    public function __construct(private array $overrides = [])
    {
        $this->store = new ArrayStore;
    }

    public function get($key): mixed
    {
        return $this->store->get($key);
    }

    public function many(array $keys): array
    {
        return $this->store->many($keys);
    }

    public function put($key, $value, $seconds): bool
    {
        return $this->store->put($key, $value, $seconds);
    }

    public function putMany(array $values, $seconds): bool
    {
        return $this->store->putMany($values, $seconds);
    }

    public function increment($key, $value = 1): int|bool
    {
        return $this->store->increment($key, $value);
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->store->decrement($key, $value);
    }

    public function forever($key, $value): bool
    {
        return $this->store->forever($key, $value);
    }

    public function touch($key, $seconds): bool
    {
        return $this->store->touch($key, $seconds);
    }

    public function forget($key): bool
    {
        return $this->store->forget($key);
    }

    public function flush(): bool
    {
        return $this->store->flush();
    }

    public function getPrefix(): string
    {
        return $this->store->getPrefix();
    }

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return $this->overrides[$name] ?? $this->store->lock($name, $seconds, $owner);
    }

    public function restoreLock($name, $owner): Lock
    {
        return $this->overrides[$name] ?? $this->store->restoreLock($name, $owner);
    }
}

/**
 * @param  array<string, Lock>  $overrides
 */
function useNamedCommandOverlapStore(array $overrides): CommandOverlapNamedLockStore
{
    $store = new CommandOverlapNamedLockStore($overrides);

    /** @var CacheManager $cache */
    $cache = app(CacheManager::class);
    $cache->extend('command-overlap-named', static fn (): Repository => new Repository($store));
    config()->set('cache.stores.command-overlap-named', ['driver' => 'command-overlap-named']);
    config()->set('swarm.commands.overlap.store', 'command-overlap-named');
    $cache->forgetDriver('command-overlap-named');

    return $store;
}

function commandOverlapLockAvailable(string $key): bool
{
    /** @var CacheManager $cache */
    $cache = app(CacheManager::class);
    $lock = $cache->store((string) config('swarm.commands.overlap.store'))->getStore()->lock($key, 60);

    if (! $lock->get()) {
        return false;
    }

    $lock->release();

    return true;
}

/**
 * @return array{durable: MockInterface, audit: MockInterface, callback: MockInterface}
 */
function relayLaneOutboxMocks(): array
{
    $mocks = [
        'durable' => Mockery::mock(DurableOutbox::class),
        'audit' => Mockery::mock(AuditOutbox::class),
        'callback' => Mockery::mock(CallbackDeliveryOutbox::class),
    ];

    app()->instance(DurableOutbox::class, $mocks['durable']);
    app()->instance(AuditOutbox::class, $mocks['audit']);
    app()->instance(CallbackDeliveryOutbox::class, $mocks['callback']);

    return $mocks;
}

/**
 * @return array<string, mixed>
 */
function lastRelayEvidence(RecordingSwarmAuditSink $sink): array
{
    $records = $sink->recordsForCategory('command.relay');

    expect($records)->toHaveCount(1);

    return $records[0];
}

test('each relay lane has its own lease key, distinct from the legacy global relay key', function (): void {
    $keys = array_map(static fn (RelayLane $lane): string => $lane->leaseKey(), RelayLane::cases());

    expect($keys)->toBe(['swarm:commands:relay:durable', 'swarm:commands:relay:audit', 'swarm:commands:relay:callback'])
        ->and($keys)->not->toContain('swarm:commands:relay')
        ->and($keys)->not->toContain(CommandOverlapGuard::RECOVER_KEY);
});

test('a relay from before per-lane leases holding the legacy global key does not block any lane', function (): void {
    $mocks = relayLaneOutboxMocks();
    $mocks['durable']->shouldReceive('drain')->once()->andReturn(new DrainResult(0, 0));
    $mocks['audit']->shouldReceive('drain')->once()->andReturn(new AuditDrainResult(0));
    $mocks['callback']->shouldReceive('drain')->once()->andReturn(new CallbackDrainResult(0));

    $lock = holdCommandOverlapLock('swarm:commands:relay');

    try {
        expect(Artisan::call('swarm:relay'))->toBe(0);
    } finally {
        $lock->release();
    }
});

test('a lane lease is held only while that lane drains', function (RelayLane $draining): void {
    $mocks = relayLaneOutboxMocks();
    $observed = [];

    $observe = static function () use (&$observed): void {
        foreach (RelayLane::cases() as $lane) {
            $observed[$lane->value] = commandOverlapLockAvailable($lane->leaseKey());
        }
    };

    $mocks['durable']->shouldReceive('drain')->once()->andReturnUsing(static function () use ($draining, $observe): DrainResult {
        if ($draining === RelayLane::Durable) {
            $observe();
        }

        return new DrainResult(0, 0);
    });
    $mocks['audit']->shouldReceive('drain')->once()->andReturnUsing(static function () use ($draining, $observe): AuditDrainResult {
        if ($draining === RelayLane::Audit) {
            $observe();
        }

        return new AuditDrainResult(0);
    });
    $mocks['callback']->shouldReceive('drain')->once()->andReturnUsing(static function () use ($draining, $observe): CallbackDrainResult {
        if ($draining === RelayLane::Callback) {
            $observe();
        }

        return new CallbackDrainResult(0);
    });

    expect(Artisan::call('swarm:relay'))->toBe(0);

    $expected = [];
    foreach (RelayLane::cases() as $lane) {
        $expected[$lane->value] = $lane !== $draining;
    }

    expect($observed)->toBe($expected);
})->with([
    'durable' => [RelayLane::Durable],
    'audit' => [RelayLane::Audit],
    'callback' => [RelayLane::Callback],
]);

test('a relay of one lane set runs while another relay holds the other lanes', function (
    array $held,
    array $types,
    array $draining,
): void {
    $mocks = relayLaneOutboxMocks();
    $results = [
        'durable' => new DrainResult(2, 0, 0, 2),
        'audit' => new AuditDrainResult(1, 0, 0, 1),
        'callback' => new CallbackDrainResult(1, 0, 0, 1),
    ];

    foreach ($mocks as $lane => $mock) {
        if (in_array($lane, $draining, true)) {
            $mock->shouldReceive('drain')->once()->andReturn($results[$lane]);
        } else {
            $mock->shouldNotReceive('drain');
        }
    }
    $sink = commandOverlapRecordingSink();

    $locks = array_map(static fn (RelayLane $lane): Lock => holdCommandOverlapLock($lane->leaseKey()), $held);

    try {
        $exit = Artisan::call('swarm:relay', ['--type' => $types]);
    } finally {
        array_map(static fn (Lock $lock): bool => $lock->release(), $locks);
    }
    $evidence = lastRelayEvidence($sink);

    expect($exit)->toBe(0)
        ->and($evidence['contended_lanes'])->toBe([])
        ->and($evidence['status'])->toBe('dispatched')
        ->and($evidence['dispatched_count'])->toBe(in_array('durable', $draining, true) ? 2 : 0)
        ->and($evidence['audit_replayed_count'])->toBe(in_array('audit', $draining, true) ? 1 : 0)
        ->and($evidence['callback_dispatched_count'])->toBe(in_array('callback', $draining, true) ? 1 : 0);
})->with([
    'step relay while audit and callback are held' => [[RelayLane::Audit, RelayLane::Callback], ['step', 'branch'], ['durable']],
    'audit and callback relay while durable is held' => [[RelayLane::Durable], ['audit', 'callback'], ['audit', 'callback']],
]);

test('a bare relay drains the lanes it can lease and exits non-zero for the contended lane', function (
    DrainResult $durableResult,
    CallbackDrainResult $callbackResult,
    string $expectedStatus,
): void {
    $mocks = relayLaneOutboxMocks();
    $mocks['durable']->shouldReceive('drain')->once()->andReturn($durableResult);
    $mocks['audit']->shouldNotReceive('drain');
    $mocks['callback']->shouldReceive('drain')->once()->andReturn($callbackResult);
    $sink = commandOverlapRecordingSink();

    $lock = holdCommandOverlapLock(RelayLane::Audit->leaseKey());

    try {
        $exit = Artisan::call('swarm:relay');
    } finally {
        $lock->release();
    }
    $output = Artisan::output();
    $evidence = lastRelayEvidence($sink);

    expect($exit)->toBe(1)
        ->and($output)->toContain('[audit] lane')
        ->and($output)->not->toContain('No pending outbox entries were found.')
        ->and($evidence['contended_lanes'])->toBe(['audit'])
        ->and($evidence['status'])->toBe($expectedStatus)
        ->and($evidence['attempts'])->toBe(1);

    if ($expectedStatus === 'none_found') {
        expect($output)->toContain('No pending entries in the lanes this run drained; held lanes [audit] were not drained.');
    }
})->with([
    'no pending work' => [new DrainResult(0, 0), new CallbackDrainResult(0), 'none_found'],
    'work dispatched' => [new DrainResult(3, 0, 0, 3), new CallbackDrainResult(1, 0, 0, 1), 'dispatched'],
    'transient failure' => [new DrainResult(0, 0, 1, 1), new CallbackDrainResult(0), 'transient_failure'],
]);

test('a single-lane relay whose lane is held is skipped as an overlap', function (): void {
    $mocks = relayLaneOutboxMocks();
    $mocks['durable']->shouldNotReceive('drain');
    $mocks['audit']->shouldNotReceive('drain');
    $mocks['callback']->shouldNotReceive('drain');
    $sink = commandOverlapRecordingSink();

    $lock = holdCommandOverlapLock(RelayLane::Durable->leaseKey());

    try {
        $exit = Artisan::call('swarm:relay', ['--type' => ['step']]);
    } finally {
        $lock->release();
    }

    expect($exit)->toBe(1)
        ->and(lastRelayEvidence($sink)['status'])->toBe('skipped_overlap')
        ->and(lastRelayEvidence($sink)['contended_lanes'])->toBe(['durable']);
});

test('an uncontended relay releases every lane lease and reports no contended lanes', function (): void {
    $mocks = relayLaneOutboxMocks();
    $mocks['durable']->shouldReceive('drain')->once()->andReturn(new DrainResult(0, 0));
    $mocks['audit']->shouldReceive('drain')->once()->andReturn(new AuditDrainResult(0));
    $mocks['callback']->shouldReceive('drain')->once()->andReturn(new CallbackDrainResult(0));
    $sink = commandOverlapRecordingSink();

    expect(Artisan::call('swarm:relay'))->toBe(0)
        ->and(lastRelayEvidence($sink)['contended_lanes'])->toBe([]);

    foreach (RelayLane::cases() as $lane) {
        expect(commandOverlapLockAvailable($lane->leaseKey()))->toBeTrue();
    }
});

test('a failing drain releases its lease, keeps observed contention, and leaves the contended lease with its holder', function (): void {
    $mocks = relayLaneOutboxMocks();
    $mocks['durable']->shouldReceive('drain')->once()->andReturn(new DrainResult(2, 0, 0, 2));
    $mocks['audit']->shouldNotReceive('drain');
    $mocks['callback']->shouldReceive('drain')->once()->andThrow(new RuntimeException('callback drain failed'));
    $sink = commandOverlapRecordingSink();

    $lock = holdCommandOverlapLock(RelayLane::Audit->leaseKey());

    try {
        expect(fn () => Artisan::call('swarm:relay'))->toThrow(RuntimeException::class, 'callback drain failed');

        expect(Artisan::output())->toContain('[audit] lane');

        $evidence = lastRelayEvidence($sink);
        expect($evidence['status'])->toBe('error')
            ->and($evidence['contended_lanes'])->toBe(['audit'])
            ->and($evidence['dispatched_count'])->toBe(2)
            ->and(commandOverlapLockAvailable(RelayLane::Durable->leaseKey()))->toBeTrue()
            ->and(commandOverlapLockAvailable(RelayLane::Callback->leaseKey()))->toBeTrue()
            ->and(commandOverlapLockAvailable(RelayLane::Audit->leaseKey()))->toBeFalse();
    } finally {
        $lock->release();
    }
});

test('a lane lease that cannot be acquired fails loud, keeps observed contention, and leaves earlier lanes released', function (): void {
    $throwing = Mockery::mock(Lock::class);
    $throwing->shouldReceive('get')->once()->andThrow(new RuntimeException('backend unavailable'));
    $store = useNamedCommandOverlapStore([RelayLane::Callback->leaseKey() => $throwing]);

    $mocks = relayLaneOutboxMocks();
    $mocks['durable']->shouldReceive('drain')->once()->andReturn(new DrainResult(1, 0, 0, 1));
    $mocks['audit']->shouldNotReceive('drain');
    $mocks['callback']->shouldNotReceive('drain');
    $sink = commandOverlapRecordingSink();

    $auditHolder = $store->lock(RelayLane::Audit->leaseKey(), 60);
    expect($auditHolder->get())->toBeTrue();

    expect(fn () => Artisan::call('swarm:relay'))
        ->toThrow(SwarmException::class, 'command overlap lease [swarm:commands:relay:callback]');

    $evidence = lastRelayEvidence($sink);
    expect(Artisan::output())->toContain('[audit] lane')
        ->and($evidence['status'])->toBe('error')
        ->and($evidence['contended_lanes'])->toBe(['audit'])
        ->and($evidence['dispatched_count'])->toBe(1)
        ->and(commandOverlapLockAvailable(RelayLane::Durable->leaseKey()))->toBeTrue();
});

test('a lane lease whose release fails surfaces the existing release error with the work already done', function (): void {
    $releaseFails = new class('swarm:commands:relay:durable', 60) extends Illuminate\Cache\Lock
    {
        public function acquire(): bool
        {
            return true;
        }

        public function release(): bool
        {
            throw new RuntimeException('release backend unavailable');
        }

        protected function getCurrentOwner(): string
        {
            return $this->owner;
        }

        public function forceRelease(): void {}
    };
    useNamedCommandOverlapStore([RelayLane::Durable->leaseKey() => $releaseFails]);

    $mocks = relayLaneOutboxMocks();
    $mocks['durable']->shouldReceive('drain')->once()->andReturn(new DrainResult(4, 0, 0, 4));
    $mocks['audit']->shouldNotReceive('drain');
    $mocks['callback']->shouldNotReceive('drain');
    $sink = commandOverlapRecordingSink();

    expect(fn () => Artisan::call('swarm:relay'))
        ->toThrow(SwarmException::class, 'could not acquire or release command overlap lease [swarm:commands:relay:durable]');

    $evidence = lastRelayEvidence($sink);
    expect($evidence['status'])->toBe('error')
        ->and($evidence['dispatched_count'])->toBe(4)
        ->and($evidence['contended_lanes'])->toBe([]);
});

test('a lane that becomes contended mid-run is dropped for the rest of the run, even after its holder lets go', function (): void {
    $mocks = relayLaneOutboxMocks();
    $sink = commandOverlapRecordingSink();
    $holder = null;
    $durableCalls = 0;

    $mocks['durable']->shouldReceive('drain')->times(4)->andReturnUsing(function () use (&$durableCalls, &$holder): DrainResult {
        $durableCalls++;

        if ($durableCalls === 2) {
            // Another relay takes the audit lane between our iterations...
            $holder = holdCommandOverlapLock(RelayLane::Audit->leaseKey());
        }

        if ($durableCalls === 3) {
            // ...and lets it go before our next audit turn.
            $holder->release();
        }

        return $durableCalls < 4 ? new DrainResult(1, 0, 0, 1) : new DrainResult(0, 0);
    });
    $mocks['audit']->shouldReceive('drain')->once()->andReturn(new AuditDrainResult(1, 0, 0, 1));
    $mocks['callback']->shouldReceive('drain')->times(4)->andReturn(new CallbackDrainResult(0));

    try {
        $exit = Artisan::call('swarm:relay', ['--drain-until-empty' => true]);
    } finally {
        $holder?->release();
    }
    $output = Artisan::output();
    $evidence = lastRelayEvidence($sink);

    expect($exit)->toBe(1)
        ->and(substr_count($output, '[audit] lane'))->toBe(1)
        ->and($evidence['contended_lanes'])->toBe(['audit'])
        ->and($evidence['status'])->toBe('dispatched')
        ->and($evidence['audit_replayed_count'])->toBe(1)
        ->and($evidence['dispatched_count'])->toBe(3)
        ->and($evidence['attempts'])->toBe(4);
});

test('a lane dropped after a transient failure still reports that failure', function (): void {
    $mocks = relayLaneOutboxMocks();
    $sink = commandOverlapRecordingSink();
    $holder = null;
    $durableCalls = 0;

    $mocks['durable']->shouldReceive('drain')->times(2)->andReturnUsing(function () use (&$durableCalls, &$holder): DrainResult {
        $durableCalls++;

        if ($durableCalls === 2) {
            $holder = holdCommandOverlapLock(RelayLane::Audit->leaseKey());

            return new DrainResult(0, 0);
        }

        return new DrainResult(1, 0, 0, 1);
    });
    $mocks['audit']->shouldReceive('drain')->once()->andReturn(new AuditDrainResult(0, 0, 1, 1));
    $mocks['callback']->shouldReceive('drain')->times(2)->andReturn(new CallbackDrainResult(0));

    try {
        $exit = Artisan::call('swarm:relay', ['--drain-until-empty' => true]);
    } finally {
        $holder?->release();
    }
    $evidence = lastRelayEvidence($sink);

    expect($exit)->toBe(1)
        ->and($evidence['status'])->toBe('transient_failure')
        ->and($evidence['failed_count'])->toBe(1)
        ->and($evidence['contended_lanes'])->toBe(['audit']);
});

test('contention mid-run neither spends nor resets the shared max-attempts budget', function (): void {
    $mocks = relayLaneOutboxMocks();
    $sink = commandOverlapRecordingSink();
    $holder = null;
    $durableCalls = 0;

    $mocks['durable']->shouldReceive('drain')->times(3)->andReturnUsing(function () use (&$durableCalls, &$holder): DrainResult {
        $durableCalls++;

        if ($durableCalls === 2) {
            $holder = holdCommandOverlapLock(RelayLane::Audit->leaseKey());
        }

        return new DrainResult(1, 0, 0, 1);
    });
    $mocks['audit']->shouldReceive('drain')->once()->andReturn(new AuditDrainResult(1, 0, 0, 1));
    $mocks['callback']->shouldReceive('drain')->times(3)->andReturn(new CallbackDrainResult(1, 0, 0, 1));

    try {
        $exit = Artisan::call('swarm:relay', ['--drain-until-empty' => true, '--max-attempts' => 3]);
    } finally {
        $holder?->release();
    }
    $evidence = lastRelayEvidence($sink);

    expect($exit)->toBe(1)
        ->and($evidence['attempts'])->toBe(3)
        ->and($evidence['dispatched_count'])->toBe(3)
        ->and($evidence['callback_dispatched_count'])->toBe(3)
        ->and($evidence['audit_replayed_count'])->toBe(1);
});

test('contended lanes are reported in lane order, not the order contention was observed', function (): void {
    $mocks = relayLaneOutboxMocks();
    $sink = commandOverlapRecordingSink();
    $holders = [];
    $durableCalls = 0;
    $callbackCalls = 0;

    $mocks['durable']->shouldReceive('drain')->times(2)->andReturnUsing(function () use (&$durableCalls, &$holders): DrainResult {
        $durableCalls++;

        if ($durableCalls === 2) {
            $holders[] = holdCommandOverlapLock(RelayLane::Audit->leaseKey());
        }

        return new DrainResult(1, 0, 0, 1);
    });
    $mocks['audit']->shouldReceive('drain')->once()->andReturn(new AuditDrainResult(1, 0, 0, 1));
    $mocks['callback']->shouldReceive('drain')->times(3)->andReturnUsing(function () use (&$callbackCalls, &$holders): CallbackDrainResult {
        $callbackCalls++;

        if ($callbackCalls === 2) {
            $holders[] = holdCommandOverlapLock(RelayLane::Durable->leaseKey());
        }

        return $callbackCalls < 3 ? new CallbackDrainResult(1, 0, 0, 1) : new CallbackDrainResult(0);
    });

    try {
        $exit = Artisan::call('swarm:relay', ['--drain-until-empty' => true]);
    } finally {
        array_map(static fn (Lock $lock): bool => $lock->release(), $holders);
    }

    expect($exit)->toBe(1)
        ->and(lastRelayEvidence($sink)['contended_lanes'])->toBe(['durable', 'audit']);
});

test('swarm recover runs while every relay lane lease is held', function (): void {
    $manager = Mockery::mock(DurableSwarmManager::class);
    $manager->shouldReceive('recover')->once()->andReturn([]);
    app()->instance(DurableSwarmManager::class, $manager);

    $locks = array_map(
        static fn (RelayLane $lane): Lock => holdCommandOverlapLock($lane->leaseKey()),
        RelayLane::cases(),
    );

    try {
        expect(Artisan::call('swarm:recover'))->toBe(0);
    } finally {
        array_map(static fn (Lock $lock): bool => $lock->release(), $locks);
    }
});

test('swarm relay help documents per-lane leases and the split schedule', function (): void {
    Artisan::call('swarm:relay', ['--help' => true]);
    $output = Artisan::output();

    expect($output)->toContain('LANE LEASES')
        ->and($output)->toContain('Each lane takes its own finite atomic lease')
        ->and($output)->toContain('held only')
        ->and($output)->toContain('swarm:relay --type=step --type=branch --type=queued_resume')
        ->and($output)->toContain('swarm:relay --type=audit --type=callback');
});
