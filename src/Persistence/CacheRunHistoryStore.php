<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\ResolvesSwarmCacheStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

class CacheRunHistoryStore implements RunHistoryStore
{
    use ResolvesSwarmCacheStore;

    public function __construct(
        protected CacheFactory $cacheFactory,
        protected ConfigRepository $config,
    ) {}

    public function start(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, int $ttlSeconds): void
    {
        $this->store()->put($this->key($runId), [
            'run_id' => $runId,
            'swarm_class' => $swarmClass,
            'topology' => $topology,
            'status' => 'running',
            'context' => $context->toArray(),
            'metadata' => $metadata,
            'steps' => [],
            'output' => null,
            'usage' => [],
            'error' => null,
            'artifacts' => [],
        ], $ttlSeconds);
    }

    public function recordStep(string $runId, SwarmStep $step, int $ttlSeconds): void
    {
        $history = $this->find($runId) ?? [];
        $history['steps'] ??= [];
        $history['steps'][] = $step->toArray();

        $this->store()->put($this->key($runId), $history, $ttlSeconds);
    }

    public function complete(string $runId, SwarmResponse $response, int $ttlSeconds): void
    {
        $history = $this->find($runId) ?? [];
        $history['status'] = 'completed';
        $history['output'] = $response->output;
        $history['usage'] = $response->usage;
        $history['context'] = $response->context?->toArray();
        $history['artifacts'] = array_map(static fn ($artifact): array => $artifact->toArray(), $response->artifacts);
        $history['metadata'] = $response->metadata;

        $this->store()->put($this->key($runId), $history, $ttlSeconds);
    }

    public function fail(string $runId, Throwable $exception, int $ttlSeconds): void
    {
        $history = $this->find($runId) ?? [];
        $history['status'] = 'failed';
        $history['error'] = [
            'message' => $exception->getMessage(),
            'class' => $exception::class,
        ];

        $this->store()->put($this->key($runId), $history, $ttlSeconds);
    }

    public function find(string $runId): ?array
    {
        /** @var array<string, mixed>|null $history */
        $history = $this->store()->get($this->key($runId));

        return $history;
    }

    protected function key(string $runId): string
    {
        return (string) $this->config->get('swarm.history.prefix', 'swarm:history:').$runId;
    }

    protected function store(): Repository
    {
        return $this->resolveCacheStore($this->cacheFactory, $this->config, 'history');
    }
}
