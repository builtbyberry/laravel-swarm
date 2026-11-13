<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\Concerns\CommandOverlapGuard;
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
use Illuminate\Cache\CacheManager;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

pest()->group('process-concurrency');

function commandOverlapRaceWorker(string $cachePath, string $barrierPath, bool $holder): Closure
{
    return static function () use ($cachePath, $barrierPath, $holder): array {
        config()->set('cache.stores.command-overlap-process', [
            'driver' => 'file',
            'path' => $cachePath,
        ]);
        config()->set('swarm.commands.overlap.store', 'command-overlap-process');
        config()->set('swarm.commands.overlap.lease_seconds', 30);

        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        app(CacheManager::class)->forgetDriver('command-overlap-process');

        $ready = $barrierPath.'/ready';
        $release = $barrierPath.'/release';
        $entered = $barrierPath.'/second-entered';

        /** @var CommandOverlapGuard $guard */
        $guard = app(CommandOverlapGuard::class);

        if ($holder) {
            $result = $guard->run(CommandOverlapGuard::RECOVER_KEY, static function () use ($ready, $release): int {
                file_put_contents($ready, 'ready');
                $deadline = microtime(true) + 5.0;

                while (! file_exists($release)) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException("Timed out waiting for command-overlap barrier [{$release}].");
                    }

                    usleep(10_000);
                }

                return 11;
            });

            return ['result' => $result, 'entered' => file_exists($entered)];
        }

        $deadline = microtime(true) + 5.0;

        while (! file_exists($ready)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Timed out waiting for command-overlap barrier [{$ready}].");
            }

            usleep(10_000);
        }

        try {
            $result = $guard->run(CommandOverlapGuard::RECOVER_KEY, static function () use ($entered): int {
                file_put_contents($entered, 'entered');

                return 22;
            });
        } finally {
            file_put_contents($release, 'release');
        }

        return ['result' => $result, 'entered' => file_exists($entered)];
    };
}

function commandOverlapCrashWorker(string $cachePath): Closure
{
    return static function () use ($cachePath): bool {
        config()->set('cache.stores.command-overlap-process', [
            'driver' => 'file',
            'path' => $cachePath,
        ]);
        config()->set('swarm.commands.overlap.store', 'command-overlap-process');
        config()->set('swarm.commands.overlap.lease_seconds', 3);

        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        app(CacheManager::class)->forgetDriver('command-overlap-process');

        /** @var CacheManager $cache */
        $cache = app(CacheManager::class);

        return $cache->store('command-overlap-process')
            ->getStore()
            ->lock(RelayLane::Durable->leaseKey(), 3)
            ->get();
    };
}

/**
 * One real swarm:relay invocation per process, sharing a file lock store.
 *
 * The slow worker runs `swarm:relay --type=audit --type=callback` and stalls inside
 * the chosen lane's drain until released. The fast worker, once the slow drain is
 * in flight, runs `swarm:relay --type=step --type=branch` and then a relay of the
 * stalled lane alone, and records what each did.
 */
function relayLaneCommandWorker(string $cachePath, string $barrierPath, string $role, string $stalledLane): Closure
{
    return static function () use ($cachePath, $barrierPath, $role, $stalledLane): array {
        config()->set('cache.stores.command-overlap-process', [
            'driver' => 'file',
            'path' => $cachePath,
        ]);
        config()->set('swarm.commands.overlap.store', 'command-overlap-process');
        config()->set('swarm.commands.overlap.lease_seconds', 30);

        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        app(CacheManager::class)->forgetDriver('command-overlap-process');

        // The provider registers late in this worker, after Artisan booted.
        app(ConsoleKernel::class)->registerCommand(app(SwarmRelayCommand::class));

        $slowEntered = $barrierPath.'/slow-entered';
        $release = $barrierPath.'/release';
        $marks = $barrierPath.'/'.$role;
        @mkdir($marks, 0777, true);

        // Bounded barrier wait (test-file functions do not exist in this worker).
        $wait = static function (string $path): void {
            $deadline = microtime(true) + 10.0;

            while (! file_exists($path)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Timed out waiting for relay-lane barrier [{$path}].");
                }

                usleep(10_000);
            }
        };

        $onDrain = static function (string $lane) use ($role, $stalledLane, $slowEntered, $release, $marks, $wait): void {
            file_put_contents($marks.'/'.$lane, 'drained');

            if ($role === 'slow' && $lane === $stalledLane) {
                file_put_contents($slowEntered, 'entered');
                $wait($release);
            }
        };

        app()->instance(DurableOutbox::class, new class($onDrain) implements DurableOutbox
        {
            public function __construct(private Closure $onDrain) {}

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
                ($this->onDrain)('durable');

                return new DrainResult(1, 0, 0, 1);
            }
        });

        app()->instance(AuditOutbox::class, new class($onDrain) implements AuditOutbox
        {
            public function __construct(private Closure $onDrain) {}

            public function enqueue(string $category, array $payload, bool $deadLetter = false): void
            {
                throw new LogicException('unused');
            }

            public function drain(int $limit = 100): AuditDrainResult
            {
                ($this->onDrain)('audit');

                return new AuditDrainResult(0);
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function assertReady(): void {}
        });

        app()->instance(CallbackDeliveryOutbox::class, new class($onDrain) implements CallbackDeliveryOutbox
        {
            public function __construct(private Closure $onDrain) {}

            public function register(string $runId, CallbackSlot $slot, Closure $callback): void
            {
                throw new LogicException('unused');
            }

            public function hasFor(string $runId): bool
            {
                return false;
            }

            public function settle(string $runId, SwarmTerminalContext $context): void
            {
                throw new LogicException('unused');
            }

            public function discard(string $runId): void
            {
                throw new LogicException('unused');
            }

            public function drain(int $limit = 100): CallbackDrainResult
            {
                ($this->onDrain)('callback');

                return new CallbackDrainResult(0);
            }

            public function deliver(int $id, string $claimToken): void
            {
                throw new LogicException('unused');
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function assertReady(): void {}
        });

        if ($role === 'slow') {
            $exit = Artisan::call('swarm:relay', ['--type' => ['audit', 'callback']]);

            return ['exit' => $exit, 'released' => file_exists($release)];
        }

        try {
            $wait($slowEntered);

            $stepExit = Artisan::call('swarm:relay', ['--type' => ['step', 'branch']]);
            $stepDrained = file_exists($marks.'/durable');

            $sameLaneExit = Artisan::call('swarm:relay', ['--type' => [$stalledLane]]);
            $sameLaneEntered = file_exists($marks.'/'.$stalledLane);

            // The slow drain must still be in flight: nothing has released it yet.
            $slowStillStalled = ! file_exists($release);
        } finally {
            file_put_contents($release, 'release');
        }

        return [
            'step_exit' => $stepExit,
            'step_drained' => $stepDrained,
            'same_lane_exit' => $sameLaneExit,
            'same_lane_entered' => $sameLaneEntered,
            'slow_still_stalled' => $slowStillStalled,
        ];
    };
}

beforeEach(function (): void {
    $this->commandOverlapRoot = sys_get_temp_dir().'/laravel-swarm-command-overlap-'.bin2hex(random_bytes(8));
    mkdir($this->commandOverlapRoot, 0777, true);
    mkdir($this->commandOverlapRoot.'/cache', 0777, true);
    mkdir($this->commandOverlapRoot.'/barriers', 0777, true);
});

afterEach(function (): void {
    if (isset($this->commandOverlapRoot)) {
        (new Filesystem)->deleteDirectory($this->commandOverlapRoot);
    }
});

test('a second real process cannot enter while the command lease is held', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = app(ConcurrencyManager::class);

    $results = $concurrency->driver('process')->run([
        commandOverlapRaceWorker($this->commandOverlapRoot.'/cache', $this->commandOverlapRoot.'/barriers', true),
        commandOverlapRaceWorker($this->commandOverlapRoot.'/cache', $this->commandOverlapRoot.'/barriers', false),
    ]);

    expect($results[0])->toBe(['result' => 11, 'entered' => false])
        ->and($results[1])->toBe(['result' => null, 'entered' => false]);
});

test('a hard-crashed process stops blocking after the finite lease expires', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = app(ConcurrencyManager::class);

    expect($concurrency->driver('process')->run([
        commandOverlapCrashWorker($this->commandOverlapRoot.'/cache'),
    ]))->toBe([true]);

    config()->set('cache.stores.command-overlap-process', [
        'driver' => 'file',
        'path' => $this->commandOverlapRoot.'/cache',
    ]);
    config()->set('swarm.commands.overlap.store', 'command-overlap-process');
    config()->set('swarm.commands.overlap.lease_seconds', 3);
    app(CacheManager::class)->forgetDriver('command-overlap-process');

    /** @var CommandOverlapGuard $guard */
    $guard = app(CommandOverlapGuard::class);
    expect($guard->run(RelayLane::Durable->leaseKey(), static fn (): int => 33))->toBeNull();

    $deadline = microtime(true) + 5.0;
    $result = null;

    while ($result === null && microtime(true) < $deadline) {
        $result = $guard->run(RelayLane::Durable->leaseKey(), static fn (): int => 33);

        if ($result === null) {
            usleep(10_000);
        }
    }

    expect($result)->toBe(33);
});

test('a step relay dispatches while another process is stalled inside an audit or callback drain', function (string $stalledLane): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = app(ConcurrencyManager::class);
    $cache = $this->commandOverlapRoot.'/cache';
    $barriers = $this->commandOverlapRoot.'/barriers';

    $results = $concurrency->driver('process')->run([
        relayLaneCommandWorker($cache, $barriers, 'slow', $stalledLane),
        relayLaneCommandWorker($cache, $barriers, 'fast', $stalledLane),
    ]);

    expect($results[1])->toBe([
        'step_exit' => 0,
        'step_drained' => true,
        'same_lane_exit' => 1,
        'same_lane_entered' => false,
        'slow_still_stalled' => true,
    ])->and($results[0])->toBe(['exit' => 0, 'released' => true]);
})->with(['audit', 'callback']);
