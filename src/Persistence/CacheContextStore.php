<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\ResolvesSwarmCacheStore;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class CacheContextStore implements ContextStore
{
    use ResolvesSwarmCacheStore;

    public function __construct(
        protected CacheFactory $cacheFactory,
        protected ConfigRepository $config,
    ) {}

    public function put(RunContext $context, int $ttlSeconds): void
    {
        $this->store()->put($this->key($context->runId), $context->toArray(), $ttlSeconds);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array
    {
        /** @var array<string, mixed>|null $context */
        $context = $this->store()->get($this->key($runId));

        return $context;
    }

    protected function key(string $runId): string
    {
        return (string) $this->config->get('swarm.context.prefix', 'swarm:context:').$runId;
    }

    protected function store(): Repository
    {
        return $this->resolveCacheStore($this->cacheFactory, $this->config, 'context');
    }
}
