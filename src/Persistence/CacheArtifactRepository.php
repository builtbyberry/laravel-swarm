<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\ResolvesSwarmCacheStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Support\PlainData;
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
            $payload = $artifact instanceof SwarmArtifact ? $artifact->toArray() : (array) $artifact;
            if (! array_key_exists('name', $payload) || ! is_string($payload['name'])) {
                throw new SwarmException('Swarm artifact payload [artifact.name] must be a string.');
            }

            if (array_key_exists('step_agent_class', $payload) && $payload['step_agent_class'] !== null && ! is_string($payload['step_agent_class'])) {
                throw new SwarmException('Swarm artifact payload [artifact.step_agent_class] must be a string or null.');
            }

            $stored[] = [
                'name' => $payload['name'],
                'content' => PlainData::value($payload['content'] ?? null, 'artifact.content'),
                'metadata' => PlainData::array(is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [], 'artifact.metadata'),
                'step_agent_class' => $payload['step_agent_class'] ?? null,
            ];
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
