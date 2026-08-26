<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Concerns;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Owns the finite command-level lease used by scheduled swarm operations.
 *
 * @internal
 */
class CommandOverlapGuard
{
    public const RECOVER_KEY = 'swarm:commands:recover';

    public const RELAY_KEY = 'swarm:commands:relay';

    public function __construct(
        private CacheManager $cache,
        private ConfigRepository $config,
    ) {}

    /**
     * @param  Closure(): int  $callback
     */
    public function run(string $key, Closure $callback): ?int
    {
        $resolved = $this->resolve();
        $callbackFailure = new class
        {
            public ?Throwable $exception = null;
        };

        try {
            $result = $resolved['store']
                ->lock($key, $resolved['lease_seconds'])
                ->get(static function () use ($callback, $callbackFailure): int {
                    try {
                        return $callback();
                    } catch (Throwable $exception) {
                        $callbackFailure->exception = $exception;

                        throw $exception;
                    }
                });
        } catch (Throwable $exception) {
            if ($callbackFailure->exception === $exception) {
                throw $exception;
            }

            throw new SwarmException(
                "Laravel Swarm could not acquire or release command overlap lease [{$key}] "
                ."using store [{$resolved['store_name']}] for [{$resolved['lease_seconds']}] seconds: "
                .$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($result === false) {
            return null;
        }

        if (! is_int($result)) {
            throw new SwarmException("Laravel Swarm command overlap callback [{$key}] returned an invalid result.");
        }

        return $result;
    }

    /**
     * Validate and describe the configured lease without acquiring it.
     *
     * @return array{store: string, driver: string, lease_seconds: int}
     */
    public function inspect(): array
    {
        $resolved = $this->resolve();

        return [
            'store' => $resolved['store_name'],
            'driver' => $resolved['driver'],
            'lease_seconds' => $resolved['lease_seconds'],
        ];
    }

    /**
     * @return array{store_name: string, driver: string, lease_seconds: int, store: LockProvider}
     */
    private function resolve(): array
    {
        $leaseSeconds = (int) $this->config->get('swarm.commands.overlap.lease_seconds', 3600);

        if ($leaseSeconds <= 0) {
            throw new SwarmException(
                'Laravel Swarm command overlap lease [swarm.commands.overlap.lease_seconds] '
                .'must be greater than zero so a crashed command eventually becomes startable again.',
            );
        }

        $storeName = $this->storeName();
        $driver = $this->config->get("cache.stores.{$storeName}.driver");

        if (in_array($driver, ['array', 'null', 'failover'], true)) {
            throw new SwarmException(
                "Laravel Swarm command overlap store [{$storeName}] uses cache driver [{$driver}], "
                .'which cannot provide the required command-level cross-process lock. Configure '
                .'swarm.commands.overlap.store with an atomic lock-capable store.',
            );
        }

        try {
            $repository = $this->cache->store($storeName);
        } catch (Throwable $exception) {
            throw new SwarmException(
                "Laravel Swarm command overlap store [{$storeName}] could not be resolved: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        $store = $repository->getStore();

        if ($store instanceof ArrayStore || $store instanceof NullStore || $store instanceof FailoverStore) {
            $storeClass = $store::class;

            throw new SwarmException(
                "Laravel Swarm command overlap store [{$storeName}] resolves to [{$storeClass}], "
                .'which cannot provide the required command-level cross-process lock.',
            );
        }

        if (! $store instanceof LockProvider) {
            throw new SwarmException(
                "Laravel Swarm command overlap store [{$storeName}] does not support atomic locks. "
                .'Configure swarm.commands.overlap.store with an atomic lock-capable store.',
            );
        }

        return [
            'store_name' => $storeName,
            'driver' => is_string($driver) ? $driver : $store::class,
            'lease_seconds' => $leaseSeconds,
            'store' => $store,
        ];
    }

    private function storeName(): string
    {
        $configured = $this->config->get('swarm.commands.overlap.store');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) $this->config->get('cache.default');
    }
}
