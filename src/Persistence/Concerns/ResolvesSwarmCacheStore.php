<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence\Concerns;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

trait ResolvesSwarmCacheStore
{
    protected function resolveCacheStore(CacheFactory $cacheFactory, ConfigRepository $config, string $configKey): CacheRepository
    {
        $store = $config->get("swarm.{$configKey}.store");

        return $store !== null && $store !== ''
            ? $cacheFactory->store((string) $store)
            : $cacheFactory->store();
    }
}
