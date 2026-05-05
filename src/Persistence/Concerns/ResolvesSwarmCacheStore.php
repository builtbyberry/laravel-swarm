<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence\Concerns;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

trait ResolvesSwarmCacheStore
{
    protected function resolveCacheStore(CacheFactory $cacheFactory, ConfigRepository $config, string $configKey): CacheRepository
    {
        $store = $config->get("swarm.{$configKey}.store");

        return $store !== null && $store !== ''
            ? $cacheFactory->store((string) $store)
            : $cacheFactory->store();
    }

    protected function assertCacheStoreReady(CacheFactory $cacheFactory, ConfigRepository $config, string $configKey, string $component): void
    {
        $storeName = $this->cacheStoreName($config, $configKey);

        try {
            $store = $this->resolveCacheStore($cacheFactory, $config, $configKey);
        } catch (Throwable $exception) {
            throw new SwarmException("Laravel Swarm [{$component}] cache store [{$storeName}] could not be resolved: {$exception->getMessage()}", previous: $exception);
        }

        $key = 'swarm:health:'.str_replace('.', ':', $configKey).':'.bin2hex(random_bytes(8));
        $value = 'ready:'.bin2hex(random_bytes(8));
        $written = false;
        $failure = null;

        try {
            if ($store->put($key, $value, 5) === false) {
                throw new SwarmException("Laravel Swarm [{$component}] cache store [{$storeName}] failed to write readiness probe.");
            }

            $written = true;

            if ($store->get($key) !== $value) {
                throw new SwarmException("Laravel Swarm [{$component}] cache store [{$storeName}] failed to read readiness probe.");
            }
        } catch (Throwable $exception) {
            $failure = $exception instanceof SwarmException
                ? $exception
                : new SwarmException("Laravel Swarm [{$component}] cache store [{$storeName}] failed readiness probe: {$exception->getMessage()}", previous: $exception);
        }

        if ($written) {
            try {
                if ($store->forget($key) === false && $failure === null) {
                    $failure = new SwarmException("Laravel Swarm [{$component}] cache store [{$storeName}] failed to delete readiness probe.");
                }
            } catch (Throwable $exception) {
                $failure ??= new SwarmException("Laravel Swarm [{$component}] cache store [{$storeName}] failed to delete readiness probe: {$exception->getMessage()}", previous: $exception);
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    protected function cacheStoreName(ConfigRepository $config, string $configKey): string
    {
        $store = $config->get("swarm.{$configKey}.store");

        return $store !== null && $store !== '' ? (string) $store : 'default';
    }
}
