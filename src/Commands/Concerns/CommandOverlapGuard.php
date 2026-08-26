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

        $result = $store->lock($key, $leaseSeconds)->get($callback);

        if ($result === false) {
            return null;
        }

        if (! is_int($result)) {
            throw new SwarmException("Laravel Swarm command overlap callback [{$key}] returned an invalid result.");
        }

        return $result;
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
