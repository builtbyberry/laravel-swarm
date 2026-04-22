<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\ResolvesSwarmCacheStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class CacheArtifactRepository implements ArtifactRepository
{
    use ResolvesSwarmCacheStore;

    public function __construct(
        protected CacheFactory $cacheFactory,
        protected ConfigRepository $config,
    ) {}

    public function storeMany(string $runId, array $artifacts, int $ttlSeconds): void
    {
        $stored = $this->all($runId);

        foreach ($artifacts as $artifact) {
            $stored[] = $artifact instanceof SwarmArtifact ? $artifact->toArray() : (array) $artifact;
        }

        $this->store()->put($this->key($runId), $stored, $ttlSeconds);
    }

    public function all(string $runId): array
    {
        /** @var array<int, array<string, mixed>>|null $artifacts */
        $artifacts = $this->store()->get($this->key($runId));

        return $artifacts ?? [];
    }

    protected function key(string $runId): string
    {
        return (string) $this->config->get('swarm.artifacts.prefix', 'swarm:artifacts:').$runId;
    }

    protected function store(): Repository
    {
        return $this->resolveCacheStore($this->cacheFactory, $this->config, 'artifacts');
    }
}
