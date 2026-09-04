<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\Concerns\CommandOverlapGuard;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Cache\CacheManager;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Filesystem\Filesystem;

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
            ->lock(CommandOverlapGuard::RELAY_KEY, 3)
            ->get();
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
    expect($guard->run(CommandOverlapGuard::RELAY_KEY, static fn (): int => 33))->toBeNull();

    $deadline = microtime(true) + 5.0;
    $result = null;

    while ($result === null && microtime(true) < $deadline) {
        $result = $guard->run(CommandOverlapGuard::RELAY_KEY, static fn (): int => 33);

        if ($result === null) {
            usleep(10_000);
        }
    }

    expect($result)->toBe(33);
});
