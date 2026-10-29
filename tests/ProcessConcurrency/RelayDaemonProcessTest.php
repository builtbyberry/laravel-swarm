<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\SwarmRelayCommand;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Enums\CallbackSlot;
use BuiltByBerry\LaravelSwarm\Enums\RelayLane;
use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;
use BuiltByBerry\LaravelSwarm\Responses\CallbackDrainResult;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;
use BuiltByBerry\LaravelSwarm\Responses\SwarmTerminalContext;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Console\Signals;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

pest()->group('process-concurrency');

/**
 * A real `swarm:relay --daemon` process.
 *
 * Its outboxes are fakes that count cycles into files under $root. Depending on
 * $scenario the durable drain blocks until a controller signals it (drain), or
 * the default cache store signals the process itself on a chosen read of the
 * queue:restart key (post-cycle-read, post-sleep-read). The callback drain, the
 * last lane of a cycle, stamps the cycle's end so a controller can act while the
 * daemon sleeps.
 */
function relayDaemonProcessWorker(string $root, string $scenario, int $sleepMs): Closure
{
    return static function () use ($root, $scenario, $sleepMs): array {
        config()->set('cache.stores.relay-daemon-overlap', ['driver' => 'file', 'path' => $root.'/overlap']);
        config()->set('swarm.commands.overlap.store', 'relay-daemon-overlap');
        config()->set('swarm.commands.overlap.lease_seconds', 30);
        config()->set('cache.stores.relay-daemon-restart', ['driver' => 'file', 'path' => $root.'/restart']);
        config()->set('cache.default', 'relay-daemon-restart');
        config()->set('swarm.durable.relay.daemon.min_sleep_ms', $sleepMs);
        config()->set('swarm.durable.relay.daemon.max_sleep_ms', $sleepMs);
        config()->set('swarm.durable.relay.daemon.backoff_multiplier', 1);

        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        // The provider registers late in this worker, after Artisan booted.
        app(ConsoleKernel::class)->registerCommand(app(SwarmRelayCommand::class));

        // Workers boot as a unit-test environment, where Laravel disables traps.
        Signals::resolveAvailabilityUsing(static fn (): bool => true);

        file_put_contents($root.'/pid', (string) getmypid());

        if (str_starts_with($scenario, 'post-')) {
            $armedRead = $scenario === 'post-cycle-read' ? 2 : 3;

            app('cache')->extend('relay-daemon-signalling', static fn (): Repository => new Repository(new class($root, $armedRead) extends ArrayStore
            {
                private int $reads = 0;

                public function __construct(private string $root, private int $armedRead)
                {
                    parent::__construct();
                }

                public function get($key): mixed
                {
                    $value = parent::get($key);

                    if ($key === 'illuminate:queue:restart' && ++$this->reads === $this->armedRead) {
                        file_put_contents($this->root.'/signalled-at-read', (string) $this->reads);
                        file_put_contents($this->root.'/signal-at', (string) microtime(true));
                        posix_kill(getmypid(), SIGTERM);
                    }

                    return $value;
                }
            }));
            config()->set('cache.stores.relay-daemon-signalling', ['driver' => 'relay-daemon-signalling']);
            config()->set('cache.default', 'relay-daemon-signalling');
        }

        $wait = static function (string $path): void {
            $deadline = microtime(true) + 10.0;

            while (! file_exists($path)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Timed out waiting for relay-daemon barrier [{$path}].");
                }

                usleep(10_000);
            }
        };

        $counter = new class
        {
            public int $durable = 0;

            public int $audit = 0;

            public int $callback = 0;
        };

        app()->instance(DurableOutbox::class, new class($counter, $scenario, $root, $wait) implements DurableOutbox
        {
            public function __construct(private object $counter, private string $scenario, private string $root, private Closure $wait) {}

            public function enqueueStep(string $runId, int $stepIndex, ?string $connection, ?string $queue, bool $directDispatch = false): int
            {
                throw new LogicException('unused');
            }

            public function enqueueBranch(string $runId, string $branchId, ?string $connection, ?string $queue, bool $directDispatch = false): int
            {
                throw new LogicException('unused');
            }

            public function enqueueQueuedResume(string $runId, ?string $connection, ?string $queue): void
            {
                throw new LogicException('unused');
            }

            public function settle(int $entryId, ?string $runId = null): void
            {
                throw new LogicException('unused');
            }

            public function drain(array $types = [], int $limit = 100): DrainResult
            {
                $cycle = ++$this->counter->durable;
                file_put_contents($this->root.'/cycles', (string) $cycle);

                if ($this->scenario === 'drain' && $cycle === 1) {
                    file_put_contents($this->root.'/in-drain', 'in-drain');
                    ($this->wait)($this->root.'/signal-sent');

                    return new DrainResult(1, 0, 0, 1);
                }

                return new DrainResult(0, 0);
            }
        });

        app()->instance(AuditOutbox::class, new class($counter) implements AuditOutbox
        {
            public function __construct(private object $counter) {}

            public function enqueue(string $category, array $payload, bool $deadLetter = false): void {}

            public function drain(int $limit = 100): AuditDrainResult
            {
                $this->counter->audit++;

                return new AuditDrainResult(0);
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function assertReady(): void {}
        });

        app()->instance(CallbackDeliveryOutbox::class, new class($counter, $root) implements CallbackDeliveryOutbox
        {
            public function __construct(private object $counter, private string $root) {}

            public function register(string $runId, CallbackSlot $slot, Closure $callback): void {}

            public function hasFor(string $runId): bool
            {
                return false;
            }

            public function settle(string $runId, SwarmTerminalContext $context): void {}

            public function discard(string $runId): void {}

            public function drain(int $limit = 100): CallbackDrainResult
            {
                $cycle = ++$this->counter->callback;
                file_put_contents($this->root.'/cycle-end-'.$cycle, (string) microtime(true));

                return new CallbackDrainResult(0);
            }

            public function deliver(int $id, string $claimToken): void {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function assertReady(): void {}
        });

        $exit = Artisan::call('swarm:relay', ['--daemon' => true, '--max-time' => 20, '--memory' => 0]);

        return [
            'exit' => $exit,
            'exited_at' => microtime(true),
            'durable' => $counter->durable,
            'audit' => $counter->audit,
            'callback' => $counter->callback,
            'output' => Artisan::output(),
        ];
    };
}

/**
 * The process that signals the daemon, or restarts it through queue:restart,
 * at a chosen point, and records what it saw.
 */
function relayDaemonProcessController(string $root, string $scenario, int $signal = 15): Closure
{
    return static function () use ($root, $scenario, $signal): array {
        config()->set('cache.stores.relay-daemon-overlap', ['driver' => 'file', 'path' => $root.'/overlap']);
        config()->set('cache.stores.relay-daemon-restart', ['driver' => 'file', 'path' => $root.'/restart']);
        config()->set('cache.default', 'relay-daemon-restart');
        app()->forgetInstance('cache.store');

        $wait = static function (string $path): void {
            $deadline = microtime(true) + 10.0;

            while (! file_exists($path)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Timed out waiting for relay-daemon barrier [{$path}].");
                }

                usleep(10_000);
            }
        };

        $wait($root.'/pid');
        $pid = (int) file_get_contents($root.'/pid');

        if ($scenario === 'drain') {
            $wait($root.'/in-drain');
            posix_kill($pid, $signal);
            file_put_contents($root.'/signal-sent', 'sent');

            return ['signalled' => true];
        }

        // Act half a second after the first cycle ended: the daemon is asleep.
        $wait($root.'/cycle-end-1');
        usleep(500_000);

        if ($scenario === 'sleep') {
            /** @var CacheManager $cache */
            $cache = app(CacheManager::class);
            $lock = $cache->store('relay-daemon-overlap')->getStore()->lock(RelayLane::Durable->leaseKey(), 30);
            $leaseFree = $lock->get();

            if ($leaseFree) {
                $lock->release();
            }

            $signalAt = microtime(true);
            posix_kill($pid, SIGTERM);

            return ['lease_free' => $leaseFree, 'signal_at' => $signalAt];
        }

        $cyclesAtRestart = (int) file_get_contents($root.'/cycles');
        $exit = Artisan::call('queue:restart');

        return ['cycles_at_restart' => $cyclesAtRestart, 'restart_exit' => $exit];
    };
}

/**
 * @param  list<Closure>  $workers
 * @return list<mixed>
 */
function relayDaemonRunProcesses(string $root, array $workers): array
{
    try {
        /** @var ConcurrencyManager $concurrency */
        $concurrency = app(ConcurrencyManager::class);

        return $concurrency->driver('process')->run($workers, 30);
    } finally {
        // A daemon that outlived a failed assertion or a timeout is killed and reaped.
        $pid = is_file($root.'/pid') ? (int) file_get_contents($root.'/pid') : 0;

        if ($pid > 0 && posix_kill($pid, 0)) {
            posix_kill($pid, SIGKILL);
        }
    }
}

function relayDaemonProcessLeaseFree(string $root, RelayLane $lane): bool
{
    config()->set('cache.stores.relay-daemon-overlap', ['driver' => 'file', 'path' => $root.'/overlap']);
    app(CacheManager::class)->forgetDriver('relay-daemon-overlap');
    $lock = app(CacheManager::class)->store('relay-daemon-overlap')->getStore()->lock($lane->leaseKey(), 30);

    if (! $lock->get()) {
        return false;
    }

    $lock->release();

    return true;
}

beforeEach(function (): void {
    if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
        $this->markTestSkipped('swarm:relay --daemon process tests require pcntl and posix.');
    }

    $this->relayDaemonRoot = sys_get_temp_dir().'/laravel-swarm-relay-daemon-'.bin2hex(random_bytes(8));
    mkdir($this->relayDaemonRoot.'/overlap', 0777, true);
    mkdir($this->relayDaemonRoot.'/restart', 0777, true);
});

afterEach(function (): void {
    if (isset($this->relayDaemonRoot)) {
        (new Filesystem)->deleteDirectory($this->relayDaemonRoot);
    }
});

test('a stop signal during a drain lets the whole cycle finish, releases every lease, and exits 0', function (int $signal): void {
    $root = $this->relayDaemonRoot;

    [$daemon, $controller] = relayDaemonRunProcesses($root, [
        relayDaemonProcessWorker($root, 'drain', 4500),
        relayDaemonProcessController($root, 'drain', $signal),
    ]);

    expect($controller)->toBe(['signalled' => true])
        ->and($daemon['exit'])->toBe(0)
        ->and($daemon['durable'])->toBe(1)
        ->and($daemon['audit'])->toBe(1)
        ->and($daemon['callback'])->toBe(1)
        ->and($daemon['output'])->toContain('Dispatched 1 outbox entry.')
        ->and($daemon['output'])->toContain('stopping after 1 cycle: signal (');

    foreach (RelayLane::cases() as $lane) {
        expect(relayDaemonProcessLeaseFree($root, $lane))->toBeTrue();
    }
})->with([
    'SIGTERM' => 15,
    'SIGINT' => 2,
]);

test('a stop signal during the idle sleep ends it promptly, and no lease is held while it sleeps', function (): void {
    $root = $this->relayDaemonRoot;

    [$daemon, $controller] = relayDaemonRunProcesses($root, [
        relayDaemonProcessWorker($root, 'sleep', 4500),
        relayDaemonProcessController($root, 'sleep'),
    ]);

    // Signalled about 0.5 s into a 4.5 s sleep: an uninterruptible wait would
    // run on for about 4 s more.
    expect($controller['lease_free'])->toBeTrue()
        ->and($daemon['exit'])->toBe(0)
        ->and($daemon['durable'])->toBe(1)
        ->and($daemon['exited_at'] - $controller['signal_at'])->toBeLessThan(1.5)
        ->and($daemon['output'])->toContain('stopping after 1 cycle: signal (');
});

test('a stop signal that arrives while the restart key is read is acted on before the next sleep or drain', function (string $scenario, int $armedRead): void {
    $root = $this->relayDaemonRoot;

    [$daemon] = relayDaemonRunProcesses($root, [
        relayDaemonProcessWorker($root, $scenario, 5000),
    ]);

    $signalAt = (float) file_get_contents($root.'/signal-at');

    // The startup read (1) never signals; the armed read comes after cycle one.
    expect((int) file_get_contents($root.'/signalled-at-read'))->toBe($armedRead)
        ->and(file_exists($root.'/cycle-end-1'))->toBeTrue()
        ->and($daemon['exit'])->toBe(0)
        ->and($daemon['durable'])->toBe(1)
        ->and($daemon['output'])->toContain('stopping after 1 cycle: signal (');

    if ($scenario === 'post-cycle-read') {
        // No full 5 s sleep after the signal.
        expect($daemon['exited_at'] - $signalAt)->toBeLessThan(1.5);
    }

    foreach (RelayLane::cases() as $lane) {
        expect(relayDaemonProcessLeaseFree($root, $lane))->toBeTrue();
    }
})->with([
    'after the cycle' => ['post-cycle-read', 2],
    'after the sleep' => ['post-sleep-read', 3],
]);

test('queue:restart in another process stops the daemon through a shared default cache, with no drain after it', function (): void {
    $root = $this->relayDaemonRoot;

    [$daemon, $controller] = relayDaemonRunProcesses($root, [
        relayDaemonProcessWorker($root, 'restart', 4000),
        relayDaemonProcessController($root, 'restart'),
    ]);

    expect($controller['restart_exit'])->toBe(0)
        ->and($daemon['exit'])->toBe(0)
        ->and($daemon['durable'])->toBe($controller['cycles_at_restart'])
        ->and($daemon['output'])->toContain(': restart (');
});
