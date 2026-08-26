<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\CommandOverlapGuard;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;
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

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('holds the command overlap lease')
        ->and($sink->recordsForCategory('command.recover'))->toHaveCount(1)
        ->and($sink->recordsForCategory('command.recover')[0]['status'])->toBe('skipped_overlap');
});

test('swarm relay contention is observable and never drains either outbox', function (): void {
    $durable = Mockery::mock(DurableOutbox::class);
    $durable->shouldNotReceive('drain');
    app()->instance(DurableOutbox::class, $durable);

    $auditOutbox = Mockery::mock(AuditOutbox::class);
    $auditOutbox->shouldNotReceive('drain');
    app()->instance(AuditOutbox::class, $auditOutbox);

    $sink = commandOverlapRecordingSink();
    $lock = holdCommandOverlapLock(CommandOverlapGuard::RELAY_KEY);

    try {
        $exit = Artisan::call('swarm:relay');
    } finally {
        $lock->release();
    }

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('holds the command overlap lease')
        ->and($sink->recordsForCategory('command.relay'))->toHaveCount(1)
        ->and($sink->recordsForCategory('command.relay')[0]['status'])->toBe('skipped_overlap');
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
