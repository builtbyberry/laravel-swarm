<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\SwarmRelayCommand;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Enums\CallbackSlot;
use BuiltByBerry\LaravelSwarm\Enums\RelayLane;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseAuditOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableOutbox;
use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;
use BuiltByBerry\LaravelSwarm\Responses\CallbackDrainResult;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;
use BuiltByBerry\LaravelSwarm\Responses\SwarmTerminalContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use Carbon\CarbonInterval;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A durable outbox whose drain is a script. Hand-written rather than mocked:
 * Mockery records every call, which would itself grow memory in the
 * footprint tests below.
 */
final class RelayDaemonDurableOutbox implements DurableOutbox
{
    public int $drains = 0;

    /**
     * @param  Closure(int): DrainResult  $drain  receives the 1-based drain number
     */
    public function __construct(private Closure $drain) {}

    public function enqueueStep(string $runId, int $stepIndex, ?string $connection, ?string $queue, bool $directDispatch = false): int
    {
        return 0;
    }

    public function enqueueBranch(string $runId, string $branchId, ?string $connection, ?string $queue, bool $directDispatch = false): int
    {
        return 0;
    }

    public function enqueueQueuedResume(string $runId, ?string $connection, ?string $queue): void {}

    public function settle(int $entryId, ?string $runId = null): void {}

    public function drain(array $types = [], int $limit = 100): DrainResult
    {
        return ($this->drain)(++$this->drains);
    }
}

final class RelayDaemonAuditOutbox implements AuditOutbox
{
    public int $drains = 0;

    /**
     * @param  Closure(int): AuditDrainResult  $drain
     */
    public function __construct(private Closure $drain) {}

    public function enqueue(string $category, array $payload, bool $deadLetter = false): void {}

    public function drain(int $limit = 100): AuditDrainResult
    {
        return ($this->drain)(++$this->drains);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function assertReady(): void {}
}

final class RelayDaemonCallbackOutbox implements CallbackDeliveryOutbox
{
    public int $drains = 0;

    /**
     * @param  Closure(int): CallbackDrainResult  $drain
     */
    public function __construct(private Closure $drain) {}

    public function register(string $runId, CallbackSlot $slot, Closure $callback): void {}

    public function hasFor(string $runId): bool
    {
        return false;
    }

    public function settle(string $runId, SwarmTerminalContext $context): void {}

    public function discard(string $runId): void {}

    public function drain(int $limit = 100): CallbackDrainResult
    {
        return ($this->drain)(++$this->drains);
    }

    public function deliver(int $id, string $claimToken): void {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function assertReady(): void {}
}

/**
 * Counts reads of the queue:restart key on the default cache store.
 */
final class RelayDaemonSpyStore extends ArrayStore
{
    public int $restartReads = 0;

    public function get($key): mixed
    {
        if ($key === 'illuminate:queue:restart') {
            $this->restartReads++;
        }

        return parent::get($key);
    }
}

/**
 * A lock-capable store whose named locks can be swapped for ones that fail.
 */
final class RelayDaemonNamedLockStore implements LockProvider, Store
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
function relayDaemonNamedLockStore(array $overrides): void
{
    $store = new RelayDaemonNamedLockStore($overrides);

    /** @var CacheManager $cache */
    $cache = app(CacheManager::class);
    $cache->extend('relay-daemon-named', static fn (): Repository => new Repository($store));
    config()->set('cache.stores.relay-daemon-named', ['driver' => 'relay-daemon-named']);
    config()->set('swarm.commands.overlap.store', 'relay-daemon-named');
    $cache->forgetDriver('relay-daemon-named');
}

/**
 * @param  (Closure(int): DrainResult)|null  $durable
 * @param  (Closure(int): AuditDrainResult)|null  $audit
 * @param  (Closure(int): CallbackDrainResult)|null  $callback
 * @return array{durable: RelayDaemonDurableOutbox, audit: RelayDaemonAuditOutbox, callback: RelayDaemonCallbackOutbox}
 */
function relayDaemonOutboxes(?Closure $durable = null, ?Closure $audit = null, ?Closure $callback = null): array
{
    $outboxes = [
        'durable' => new RelayDaemonDurableOutbox($durable ?? static fn (): DrainResult => new DrainResult(0, 0)),
        'audit' => new RelayDaemonAuditOutbox($audit ?? static fn (): AuditDrainResult => new AuditDrainResult(0)),
        'callback' => new RelayDaemonCallbackOutbox($callback ?? static fn (): CallbackDrainResult => new CallbackDrainResult(0)),
    ];

    app()->instance(DurableOutbox::class, $outboxes['durable']);
    app()->instance(AuditOutbox::class, $outboxes['audit']);
    app()->instance(CallbackDeliveryOutbox::class, $outboxes['callback']);

    return $outboxes;
}

function relayDaemonRecordingSink(): RecordingSwarmAuditSink
{
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    return $sink;
}

/**
 * Fake every sleep, advancing the clock by each one, and return the list the
 * daemon's sleeps are recorded into, in milliseconds.
 *
 * @return ArrayObject<int, int>
 */
function relayDaemonFakeSleep(): ArrayObject
{
    /** @var ArrayObject<int, int> $sleeps */
    $sleeps = new ArrayObject;

    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(static function (CarbonInterval $duration) use ($sleeps): void {
        $sleeps[] = (int) $duration->totalMilliseconds;
    });

    return $sleeps;
}

/**
 * The slices the daemon sleeps in for each per-cycle wait.
 *
 * @param  list<int>  $waits
 * @return list<int>
 */
function relayDaemonSlices(array $waits): array
{
    $slices = [];

    foreach ($waits as $wait) {
        while ($wait > 0) {
            $slices[] = min(500, $wait);
            $wait -= 500;
        }
    }

    return $slices;
}

function relayDaemonLaneLeaseFree(RelayLane $lane): bool
{
    $lock = app(CacheManager::class)->store((string) config('swarm.commands.overlap.store'))->getStore()->lock($lane->leaseKey(), 60);

    if (! $lock->get()) {
        return false;
    }

    $lock->release();

    return true;
}

function relayDaemonHoldLane(RelayLane $lane): Lock
{
    $lock = app(CacheManager::class)->store((string) config('swarm.commands.overlap.store'))->getStore()->lock($lane->leaseKey(), 60);

    expect($lock->get())->toBeTrue();

    return $lock;
}

/**
 * Clear Sleep's recorded fake sequence without dropping its callbacks, so the
 * footprint tests measure the daemon rather than the fake's bookkeeping.
 */
function relayDaemonForgetFakeSleeps(): void
{
    (static function (): void {
        static::$sequence = [];
    })->bindTo(null, Sleep::class)();
}

beforeEach(function (): void {
    if (! extension_loaded('pcntl')) {
        $this->markTestSkipped('swarm:relay --daemon requires the pcntl extension.');
    }

    Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
    config()->set('swarm.durable.relay.daemon.min_sleep_ms', 100);
    config()->set('swarm.durable.relay.daemon.max_sleep_ms', 1000);
    config()->set('swarm.durable.relay.daemon.backoff_multiplier', 2);
});

afterEach(function (): void {
    Sleep::fake(false);
    Carbon::setTestNow();
    Worker::$restartable = true;
});

test('the daemon sleeps short after work, backs off to the ceiling when idle, and drains nothing once max-time is reached', function (): void {
    // Cycles 1-5 idle, 6 productive, 7-8 idle.
    $outboxes = relayDaemonOutboxes(durable: static fn (int $n): DrainResult => $n === 6 ? new DrainResult(1, 0, 0, 1) : new DrainResult(0, 0));
    $sleeps = relayDaemonFakeSleep();

    $exit = Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 4]);

    // 200+400+800+1000+1000 idle, 100 after work, 200 idle = 3700 ms; the eighth
    // cycle's 400 ms wait is capped to the 300 ms left, and nothing drains after.
    expect($exit)->toBe(0)
        ->and($outboxes['durable']->drains)->toBe(8)
        ->and($outboxes['audit']->drains)->toBe(8)
        ->and($outboxes['callback']->drains)->toBe(8)
        ->and($sleeps->getArrayCopy())->toBe(relayDaemonSlices([200, 400, 800, 1000, 1000, 100, 200, 300]))
        ->and(Artisan::output())->toContain('swarm:relay daemon stopping after 8 cycles: max_time.');
});

test('a cycle that only failed, only met contention, or only touched the audit lane backs off instead of resetting', function (string $shape): void {
    $holder = null;
    relayDaemonOutboxes(
        durable: static function (int $n) use ($shape): DrainResult {
            return $shape === 'transient' ? new DrainResult(0, 0, 1, 1) : new DrainResult(0, 0);
        },
        audit: static function (int $n) use ($shape): AuditDrainResult {
            return $shape === 'audit-only' ? new AuditDrainResult(1, 1, 0, 2) : new AuditDrainResult(0);
        },
    );
    $sleeps = relayDaemonFakeSleep();

    if ($shape === 'contention') {
        $holder = relayDaemonHoldLane(RelayLane::Callback);
    }

    try {
        expect(Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 2]))->toBe(0);
    } finally {
        $holder?->release();
    }

    // Never back to the 100 ms floor: 200, 400, 800, then the 600 ms left.
    expect($sleeps->getArrayCopy())->toBe(relayDaemonSlices([200, 400, 800, 600]));
})->with(['transient', 'contention', 'audit-only']);

test('a lane lease is held while its lane drains and no lease is held across a sleep', function (): void {
    $heldWhileDraining = [];
    $freeAtEverySleep = true;

    relayDaemonOutboxes(
        durable: static function () use (&$heldWhileDraining): DrainResult {
            $heldWhileDraining[] = ! relayDaemonLaneLeaseFree(RelayLane::Durable);

            return new DrainResult(0, 0);
        },
    );
    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(static function () use (&$freeAtEverySleep): void {
        foreach (RelayLane::cases() as $lane) {
            $freeAtEverySleep = $freeAtEverySleep && relayDaemonLaneLeaseFree($lane);
        }
    });

    expect(Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 2]))->toBe(0)
        ->and($heldWhileDraining)->toBe([true, true, true, true])
        ->and($freeAtEverySleep)->toBeTrue();
});

test('a held lane is skipped for that cycle only and drained on the next one', function (): void {
    $sink = relayDaemonRecordingSink();
    $holder = relayDaemonHoldLane(RelayLane::Durable);

    $outboxes = relayDaemonOutboxes(durable: static fn (int $n): DrainResult => $n === 1 ? new DrainResult(2, 0, 0, 2) : new DrainResult(0, 0));
    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(static function () use (&$holder): void {
        $holder?->release();
        $holder = null;
    });

    try {
        $exit = Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 1]);
    } finally {
        $holder?->release();
    }

    $events = $sink->recordsForCategory('command.relay');

    expect($exit)->toBe(0)
        ->and($outboxes['durable']->drains)->toBeGreaterThanOrEqual(1)
        ->and(Artisan::output())->toContain('skipping that lane this cycle')
        ->and($events)->toHaveCount(2)
        ->and($events[0]['contended_lanes'])->toBe(['durable'])
        ->and($events[0]['attempts'])->toBe(1)
        ->and($events[0]['status'])->toBe('none_found')
        ->and($events[1]['contended_lanes'])->toBe([])
        ->and($events[1]['dispatched_count'])->toBe(2)
        ->and($events[1]['status'])->toBe('dispatched');
});

test('a cycle whose every lane is held reports skipped_overlap with no attempt, like a one-shot run', function (): void {
    $sink = relayDaemonRecordingSink();
    relayDaemonOutboxes();
    $locks = array_map(static fn (RelayLane $lane): Lock => relayDaemonHoldLane($lane), RelayLane::cases());
    Sleep::fake(syncWithCarbon: true);

    try {
        expect(Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 1]))->toBe(0);
    } finally {
        foreach ($locks as $lock) {
            $lock->release();
        }
    }

    $events = $sink->recordsForCategory('command.relay');

    expect($events)->not->toBeEmpty()
        ->and($events[0]['status'])->toBe('skipped_overlap')
        ->and($events[0]['attempts'])->toBe(0)
        ->and($events[0]['dispatched_count'])->toBe(0)
        ->and($events[0]['contended_lanes'])->toBe(['durable', 'audit', 'callback']);
});

test('idle and audit-only cycles emit no evidence while a cycle with durable or callback work emits one event', function (): void {
    $sink = relayDaemonRecordingSink();
    relayDaemonOutboxes(
        durable: static fn (int $n): DrainResult => $n === 3 ? new DrainResult(1, 0, 0, 1) : new DrainResult(0, 0),
        audit: static fn (int $n): AuditDrainResult => $n === 2 || $n === 3 ? new AuditDrainResult(1, 0, 0, 1) : new AuditDrainResult(0),
        callback: static fn (int $n): CallbackDrainResult => $n === 4 ? new CallbackDrainResult(0, 0, 1, 1) : new CallbackDrainResult(0),
    );
    Sleep::fake(syncWithCarbon: true);

    // Cycle 1 idle; 2 audit-only; 3 durable work (with an audit replay); 4 a
    // callback transient failure; then idle until max-time.
    expect(Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 2]))->toBe(0);

    $events = $sink->recordsForCategory('command.relay');

    expect($events)->toHaveCount(2)
        ->and($events[0]['status'])->toBe('dispatched')
        ->and($events[0]['attempts'])->toBe(1)
        ->and($events[0]['drain_until_empty'])->toBeFalse()
        ->and($events[0]['max_attempts'])->toBeNull()
        ->and($events[0]['dispatched_count'])->toBe(1)
        ->and($events[0]['audit_replayed_count'])->toBe(1)
        ->and($events[1]['status'])->toBe('transient_failure')
        ->and($events[1]['failed_count'])->toBe(1);
});

test('an exception in a later lane reports the earlier lanes and held lanes of that cycle, releases its lease, and fails', function (): void {
    $sink = relayDaemonRecordingSink();
    relayDaemonOutboxes(
        durable: static fn (): DrainResult => new DrainResult(2, 0, 0, 2),
        callback: static fn (): CallbackDrainResult => throw new RuntimeException('callback drain failed'),
    );
    $holder = relayDaemonHoldLane(RelayLane::Audit);
    Sleep::fake(syncWithCarbon: true);

    try {
        expect(fn () => Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 5]))
            ->toThrow(RuntimeException::class, 'callback drain failed');
    } finally {
        $holder->release();
    }

    $events = $sink->recordsForCategory('command.relay');

    expect($events)->toHaveCount(1)
        ->and($events[0]['status'])->toBe('error')
        ->and($events[0]['exception_class'])->toBe(RuntimeException::class)
        ->and($events[0]['dispatched_count'])->toBe(2)
        ->and($events[0]['contended_lanes'])->toBe(['audit'])
        ->and(relayDaemonLaneLeaseFree(RelayLane::Durable))->toBeTrue()
        ->and(relayDaemonLaneLeaseFree(RelayLane::Callback))->toBeTrue();
});

test('a lane lease whose release fails reports the work its drain already did', function (): void {
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
    relayDaemonNamedLockStore([RelayLane::Durable->leaseKey() => $releaseFails]);

    $sink = relayDaemonRecordingSink();
    $outboxes = relayDaemonOutboxes(durable: static fn (): DrainResult => new DrainResult(4, 0, 0, 4));
    Sleep::fake(syncWithCarbon: true);

    expect(fn () => Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 5]))
        ->toThrow(SwarmException::class, 'could not acquire or release command overlap lease [swarm:commands:relay:durable]');

    $events = $sink->recordsForCategory('command.relay');

    expect($events)->toHaveCount(1)
        ->and($events[0]['status'])->toBe('error')
        ->and($events[0]['dispatched_count'])->toBe(4)
        ->and($outboxes['audit']->drains)->toBe(0);
});

test('a lane lease that cannot be acquired fails loud and keeps the earlier lanes of that cycle', function (): void {
    $throwing = Mockery::mock(Lock::class);
    $throwing->shouldReceive('get')->once()->andThrow(new RuntimeException('backend unavailable'));
    relayDaemonNamedLockStore([RelayLane::Callback->leaseKey() => $throwing]);

    $sink = relayDaemonRecordingSink();
    relayDaemonOutboxes(durable: static fn (): DrainResult => new DrainResult(1, 0, 0, 1));
    Sleep::fake(syncWithCarbon: true);

    expect(fn () => Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 5]))
        ->toThrow(SwarmException::class, 'command overlap lease [swarm:commands:relay:callback]');

    $events = $sink->recordsForCategory('command.relay');

    expect($events)->toHaveCount(1)
        ->and($events[0]['status'])->toBe('error')
        ->and($events[0]['dispatched_count'])->toBe(1);
});

test('reaching the memory limit stops the daemon after the cycle in progress', function (): void {
    $outboxes = relayDaemonOutboxes();
    $sleeps = relayDaemonFakeSleep();

    $exit = Artisan::call('swarm:relay', ['--daemon' => true, '--memory' => 1]);

    expect($exit)->toBe(0)
        ->and($outboxes['durable']->drains)->toBe(1)
        ->and($sleeps->getArrayCopy())->toBe([])
        ->and(Artisan::output())->toContain('stopping after 1 cycle: memory.');
});

test('invalid daemon limits fail before anything is drained or audited', function (string $option, string $value): void {
    $sink = relayDaemonRecordingSink();
    $outboxes = relayDaemonOutboxes();

    $exit = Artisan::call('swarm:relay', ['--daemon' => true, "--{$option}" => $value]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("--{$option} must be a whole number")
        ->and($outboxes['durable']->drains)->toBe(0)
        ->and($sink->recordsForCategory('command.relay'))->toBe([]);
})->with([
    'negative max-time' => ['max-time', '-1'],
    'fractional max-time' => ['max-time', '1.5'],
    'word max-time' => ['max-time', 'abc'],
    'empty max-time' => ['max-time', ''],
    'oversized max-time' => ['max-time', '99999999999999999999'],
    'negative memory' => ['memory', '-1'],
    'word memory' => ['memory', 'abc'],
    'percent memory' => ['memory', '50%'],
    'empty memory' => ['memory', ''],
]);

test('zero max-time and zero memory mean no limit', function (): void {
    $outboxes = relayDaemonOutboxes();
    Sleep::fake();
    $cycles = 0;
    Sleep::whenFakingSleep(static function () use (&$cycles): void {
        // Stop through queue:restart after a few cycles, since neither limit will.
        if (++$cycles === 6) {
            app('cache')->store()->forever('illuminate:queue:restart', 'later');
        }
    });

    expect(Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => '0', '--memory' => '0']))->toBe(0)
        ->and($outboxes['durable']->drains)->toBeGreaterThan(1)
        ->and(Artisan::output())->toContain(': restart.');
});

test('max-time and memory without --daemon warn and run a normal one-shot relay', function (): void {
    $outboxes = relayDaemonOutboxes();

    expect(Artisan::call('swarm:relay', ['--max-time' => 'abc', '--memory' => '64']))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('--max-time has no effect without --daemon')
        ->and($output)->toContain('--memory has no effect without --daemon')
        ->and($outboxes['durable']->drains)->toBe(1);
});

test('invalid daemon sleep configuration fails loud before anything is drained', function (string $key, mixed $value, string $message): void {
    $outboxes = relayDaemonOutboxes();
    config()->set("swarm.durable.relay.daemon.{$key}", $value);

    expect(Artisan::call('swarm:relay', ['--daemon' => true]))->toBe(1)
        ->and(Artisan::output())->toContain("[swarm.durable.relay.daemon.{$key}] must be {$message}")
        ->and($outboxes['durable']->drains)->toBe(0);
})->with([
    'zero floor' => ['min_sleep_ms', 0, 'a whole number of milliseconds from 1'],
    'fractional floor' => ['min_sleep_ms', '1.5', 'a whole number of milliseconds from 1'],
    'exponent floor' => ['min_sleep_ms', '1e3', 'a whole number of milliseconds from 1'],
    'empty floor' => ['min_sleep_ms', '', 'a whole number of milliseconds from 1'],
    'oversized floor' => ['min_sleep_ms', '99999999999999999999', 'a whole number of milliseconds from 1'],
    'float floor' => ['min_sleep_ms', 1.5, 'a whole number of milliseconds from 1'],
    'ceiling below floor' => ['max_sleep_ms', 50, 'a whole number of milliseconds from 100'],
    'ceiling above a minute' => ['max_sleep_ms', 60_001, 'a whole number of milliseconds from 100'],
    'multiplier below one' => ['backoff_multiplier', 0.5, 'a finite number from 1 to 10'],
    'multiplier above ten' => ['backoff_multiplier', 11, 'a finite number from 1 to 10'],
    'infinite multiplier' => ['backoff_multiplier', INF, 'a finite number from 1 to 10'],
    'nan multiplier' => ['backoff_multiplier', NAN, 'a finite number from 1 to 10'],
    'word multiplier' => ['backoff_multiplier', 'abc', 'a finite number from 1 to 10'],
]);

test('environment-style string sleep configuration is accepted', function (): void {
    config()->set('swarm.durable.relay.daemon.min_sleep_ms', '100');
    config()->set('swarm.durable.relay.daemon.max_sleep_ms', '400');
    config()->set('swarm.durable.relay.daemon.backoff_multiplier', '2.0');
    relayDaemonOutboxes();
    $sleeps = relayDaemonFakeSleep();

    expect(Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 1]))->toBe(0)
        ->and($sleeps->getArrayCopy())->toBe([200, 400, 400]);
});

test('daemon and drain-until-empty cannot be combined', function (): void {
    $sink = relayDaemonRecordingSink();
    $outboxes = relayDaemonOutboxes();

    expect(Artisan::call('swarm:relay', ['--daemon' => true, '--drain-until-empty' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('--daemon and --drain-until-empty cannot be combined')
        ->and($outboxes['durable']->drains)->toBe(0)
        ->and($sink->recordsForCategory('command.relay'))->toBe([]);
});

test('daemon mode refuses to start without pcntl', function (): void {
    $outboxes = relayDaemonOutboxes();
    $command = new class extends SwarmRelayCommand
    {
        protected function supportsAsyncSignals(): bool
        {
            return false;
        }
    };
    $command->setLaravel(app());
    app(ConsoleKernel::class)->registerCommand($command);

    expect(Artisan::call('swarm:relay', ['--daemon' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('requires the pcntl extension')
        ->and($outboxes['durable']->drains)->toBe(0);
});

test('a scheduled one-shot relay never resolves the default cache store', function (): void {
    config()->set('cache.stores.relay-daemon-broken', ['driver' => 'relay-daemon-missing-driver']);
    config()->set('cache.default', 'relay-daemon-broken');
    $outboxes = relayDaemonOutboxes();

    expect(Artisan::call('swarm:relay'))->toBe(0)
        ->and($outboxes['durable']->drains)->toBe(1);
});

test('the daemon resolves the default cache store only after its options validate', function (): void {
    config()->set('cache.stores.relay-daemon-broken', ['driver' => 'relay-daemon-missing-driver']);
    config()->set('cache.default', 'relay-daemon-broken');
    $outboxes = relayDaemonOutboxes();

    expect(Artisan::call('swarm:relay', ['--daemon' => true, '--memory' => 'abc']))->toBe(1)
        ->and(Artisan::output())->toContain('--memory must be a whole number')
        ->and(fn () => Artisan::call('swarm:relay', ['--daemon' => true]))->toThrow(InvalidArgumentException::class)
        ->and($outboxes['durable']->drains)->toBe(0);
});

test('queue:restart stops the daemon at its next check, with no drain after it', function (): void {
    $outboxes = relayDaemonOutboxes();
    $drainsAtRestart = null;
    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(static function () use (&$drainsAtRestart, $outboxes): void {
        if ($drainsAtRestart === null && $outboxes['durable']->drains === 3) {
            $drainsAtRestart = $outboxes['durable']->drains;
            Artisan::call('queue:restart');
        }
    });

    // The daemon writes to its own buffer: the nested queue:restart call replaces Artisan::output().
    $output = new BufferedOutput;

    expect(Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 60], $output))->toBe(0)
        ->and($output->fetch())->toContain('stopping after 3 cycles: restart.')
        ->and($outboxes['durable']->drains)->toBe($drainsAtRestart);
});

test('a restart signal already present when the daemon starts does not stop it', function (): void {
    app('cache')->store()->forever('illuminate:queue:restart', 1_700_000_000);
    relayDaemonOutboxes();
    Sleep::fake(syncWithCarbon: true);

    expect(Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 1]))->toBe(0)
        ->and(Artisan::output())->toContain(': max_time.');
});

test('the restart key is read at start and at each check, and not at all when queue restarts are disabled', function (bool $restartable): void {
    $store = new RelayDaemonSpyStore;
    app('cache')->extend('relay-daemon-spy', static fn (): Repository => new Repository($store));
    config()->set('cache.stores.relay-daemon-spy', ['driver' => 'relay-daemon-spy']);
    config()->set('cache.default', 'relay-daemon-spy');
    app('cache')->forgetDriver('relay-daemon-spy');
    Worker::$restartable = $restartable;

    relayDaemonOutboxes();
    Sleep::fake(syncWithCarbon: true);

    expect(Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 1]))->toBe(0);

    // max-time 1 s: 200 + 400 + 400 ms, so 3 cycles, each checked after the
    // cycle and after the sleep, plus the startup read.
    expect($store->restartReads)->toBe($restartable ? 1 + 3 * 2 : 0);
})->with([
    'enabled' => true,
    'disabled' => false,
]);

test('a failing audit sink cannot feed the daemon its own evidence', function (): void {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.audit.failure_policy', 'queue');
    config()->set('swarm.audit.outbox.max_attempts', 3);
    config()->set('swarm.durable.relay.daemon.max_sleep_ms', 60_000);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    app()->instance(SwarmAuditSink::class, new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('sink down');
        }
    });

    foreach ([SwarmAuditDispatcher::class, AuditOutbox::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $auditOutbox = app(AuditOutbox::class);
    expect($auditOutbox)->toBeInstanceOf(DatabaseAuditOutbox::class)
        ->and($auditOutbox->isAvailable())->toBeTrue();

    app()->instance(DurableOutbox::class, new RelayDaemonDurableOutbox(
        static fn (int $n): DrainResult => $n === 1 ? new DrainResult(1, 0, 0, 1) : new DrainResult(0, 0),
    ));

    $table = (string) config('swarm.tables.audit_outbox', 'swarm_audit_outbox');
    $rowsAfterCycle = [];
    $sleeps = [];
    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(static function (CarbonInterval $duration) use (&$rowsAfterCycle, &$sleeps, $table): void {
        $sleeps[] = (int) $duration->totalMilliseconds;
        $rows = DB::table($table)->orderBy('id')->get(['id', 'category', 'attempts', 'status']);
        $rowsAfterCycle[] = $rows->map(static fn (object $row): string => "{$row->id}:{$row->category}:{$row->attempts}:{$row->status}")->all();
    });

    // 100 ms after the productive first cycle, then 200, 400 ... while idle.
    expect(Artisan::call('swarm:relay', ['--daemon' => true, '--type' => ['step', 'audit'], '--max-time' => 30]))->toBe(0);

    // One row, the first cycle's own evidence, retried each later cycle until it
    // is dead-lettered at the cap, and never joined by a second row.
    $rows = array_map(static fn (array $snapshot): string => $snapshot[0] ?? 'none', $rowsAfterCycle);

    expect(array_unique(array_map('count', $rowsAfterCycle)))->toBe([1])
        ->and(array_values(array_unique($rows)))->toBe([
            '1:command.relay:0:pending',
            '1:command.relay:1:pending',
            '1:command.relay:2:pending',
            '1:command.relay:3:dead_letter',
        ])
        ->and($sleeps[0])->toBe(100)
        ->and(array_slice($sleeps, 1, 4))->toBe([200, 400, 500, 300]);
});

/**
 * Run the daemon for exactly $cycles idle cycles with a 1 ms sleep, the clock
 * advancing one second per sleep, and return memory samples taken after the
 * $sampleAt-th and the last cycle. Sleep's own fake bookkeeping is cleared on
 * every sleep so only the daemon's footprint is measured.
 *
 * @return array{cycles: int, samples: array<int, int>, exit: int}
 */
function relayDaemonIdleFootprint(int $cycles, int $sampleAt): array
{
    config()->set('swarm.durable.relay.daemon.min_sleep_ms', 1);
    config()->set('swarm.durable.relay.daemon.max_sleep_ms', 1);
    DB::disableQueryLog();

    $state = new class
    {
        public int $sleeps = 0;

        /** @var array<int, int> */
        public array $samples = [];
    };

    Sleep::fake();
    Sleep::whenFakingSleep(static function () use ($state, $cycles, $sampleAt): void {
        $state->sleeps++;
        relayDaemonForgetFakeSleeps();
        Carbon::setTestNow(Carbon::now()->addSecond());

        if ($state->sleeps === $sampleAt || $state->sleeps === $cycles) {
            gc_collect_cycles();
            $state->samples[$state->sleeps] = memory_get_usage();
        }
    });

    $exit = Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => $cycles, '--memory' => 0]);

    return ['cycles' => $state->sleeps, 'samples' => $state->samples, 'exit' => $exit];
}

test('idle cycles do not grow the daemon\'s memory', function (): void {
    $outboxes = relayDaemonOutboxes();

    $run = relayDaemonIdleFootprint(6000, 1000);

    expect($run['exit'])->toBe(0)
        ->and($run['cycles'])->toBe(6000)
        ->and($outboxes['durable']->drains)->toBe(6000)
        ->and(array_keys($run['samples']))->toBe([1000, 6000])
        ->and($run['samples'][6000] - $run['samples'][1000])->toBeLessThan(16 * 1024);
});

test('idle cycles over the bundled database outboxes do not grow the daemon\'s memory', function (): void {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.callbacks.enabled', true);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    foreach ([DurableOutbox::class, AuditOutbox::class, CallbackDeliveryOutbox::class, SwarmAuditDispatcher::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    expect(app(DurableOutbox::class))->toBeInstanceOf(DatabaseDurableOutbox::class)
        ->and(app(AuditOutbox::class))->toBeInstanceOf(DatabaseAuditOutbox::class)
        ->and(app(CallbackDeliveryOutbox::class))->toBeInstanceOf(DatabaseCallbackDeliveryOutbox::class)
        ->and(app(AuditOutbox::class)->isAvailable())->toBeTrue()
        ->and(app(CallbackDeliveryOutbox::class)->isAvailable())->toBeTrue();

    // Scalar counters only: prove each lane queried its own table every cycle.
    $tables = [
        (string) config('swarm.tables.durable_outbox'),
        (string) config('swarm.tables.audit_outbox'),
        (string) config('swarm.tables.callback_deliveries'),
    ];
    $hits = new class
    {
        public int $durable = 0;

        public int $audit = 0;

        public int $callback = 0;
    };
    DB::listen(static function (QueryExecuted $query) use ($hits, $tables): void {
        if (str_contains($query->sql, $tables[0])) {
            $hits->durable++;
        }

        if (str_contains($query->sql, $tables[1])) {
            $hits->audit++;
        }

        if (str_contains($query->sql, $tables[2])) {
            $hits->callback++;
        }
    });

    $run = relayDaemonIdleFootprint(3000, 500);

    expect($run['exit'])->toBe(0)
        ->and($run['cycles'])->toBe(3000)
        ->and($hits->durable)->toBeGreaterThanOrEqual(3000)
        ->and($hits->audit)->toBeGreaterThanOrEqual(3000)
        ->and($hits->callback)->toBeGreaterThanOrEqual(3000)
        ->and(array_keys($run['samples']))->toBe([500, 3000])
        ->and($run['samples'][3000] - $run['samples'][500])->toBeLessThan(16 * 1024);
});

test('swarm:relay --help documents daemon mode', function (): void {
    Artisan::call('swarm:relay', ['--help' => true]);
    $help = Artisan::output();

    expect($help)->toContain('--daemon')
        ->and($help)->toContain('--max-time')
        ->and($help)->toContain('--memory')
        ->and($help)->toContain('DAEMON MODE')
        ->and($help)->toContain('queue:restart');
});
