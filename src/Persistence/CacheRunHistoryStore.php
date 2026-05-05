<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\ResolvesSwarmCacheStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\PersistedRunContextMatcher;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Throwable;

class CacheRunHistoryStore implements RunHistoryStore
{
    use ResolvesSwarmCacheStore;

    public function __construct(
        protected CacheFactory $cacheFactory,
        protected ConfigRepository $config,
        protected SwarmCapture $capture,
    ) {}

    public function start(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, int $ttlSeconds): void
    {
        $timestamp = Carbon::now('UTC')->toIso8601String();

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
            'started_at' => $timestamp,
            'finished_at' => null,
            'updated_at' => $timestamp,
        ], $ttlSeconds);

        $this->appendToIndex($this->swarmIndexKey($swarmClass), $runId, $ttlSeconds);
        $this->appendToIndex($this->latestIndexKey(), $runId, $ttlSeconds);
    }

    public function recordStep(string $runId, SwarmStep $step, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $history = $this->find($runId) ?? [];
        $history['steps'] ??= [];
        $history['steps'][] = $step->toArray();
        $history['updated_at'] = Carbon::now('UTC')->toIso8601String();

        $this->store()->put($this->key($runId), $history, $ttlSeconds);
    }

    public function complete(string $runId, SwarmResponse $response, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $history = $this->find($runId) ?? [];
        $history['status'] = 'completed';
        $history['output'] = $response->output;
        $history['usage'] = $response->usage;
        $history['context'] = $response->context?->toArray();
        $history['artifacts'] = array_map(static fn ($artifact): array => $artifact->toArray(), $response->artifacts);
        $history['metadata'] = $response->metadata;
        $history['finished_at'] = Carbon::now('UTC')->toIso8601String();
        $history['updated_at'] = $history['finished_at'];

        $this->store()->put($this->key($runId), $history, $ttlSeconds);
    }

    public function fail(string $runId, Throwable $exception, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $history = $this->find($runId) ?? [];
        $history['status'] = 'failed';
        $history['error'] = [
            'message' => $this->capture->failureMessage($exception),
            'class' => $exception::class,
        ];
        $history['finished_at'] = Carbon::now('UTC')->toIso8601String();
        $history['updated_at'] = $history['finished_at'];

        $this->store()->put($this->key($runId), $history, $ttlSeconds);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array
    {
        /** @var array<string, mixed>|null $history */
        $history = $this->store()->get($this->key($runId));

        return $history;
    }

    public function findMatching(string $swarmClass, ?string $status, ?array $contextSubset): iterable
    {
        $runIds = $this->store()->get($this->swarmIndexKey($swarmClass), []);

        if (! is_array($runIds)) {
            return;
        }

        foreach (array_reverse($runIds) as $runId) {
            if (! is_string($runId)) {
                continue;
            }

            $record = $this->find($runId);

            if (! is_array($record)) {
                continue;
            }

            if ($status !== null && ($record['status'] ?? null) !== $status) {
                continue;
            }

            if ($contextSubset !== null && ! PersistedRunContextMatcher::matchesRecord($contextSubset, $record)) {
                continue;
            }

            yield $record;
        }
    }

    public function query(?string $swarmClass = null, ?string $status = null, int $limit = 25): array
    {
        $runIds = $this->store()->get(
            $swarmClass !== null ? $this->swarmIndexKey($swarmClass) : $this->latestIndexKey(),
            [],
        );

        if (! is_array($runIds)) {
            return [];
        }

        $records = collect(array_reverse($runIds))
            ->map(fn (mixed $runId): ?array => is_string($runId) ? $this->find($runId) : null)
            ->filter()
            ->when($status !== null, fn ($collection) => $collection->where('status', $status))
            ->take($limit)
            ->values()
            ->all();

        /** @var array<int, array<string, mixed>> $records */
        return $records;
    }

    public function assertReady(): void
    {
        $this->assertCacheStoreReady($this->cacheFactory, $this->config, 'history', 'history');
    }

    protected function key(string $runId): string
    {
        return (string) $this->config->get('swarm.history.prefix', 'swarm:history:').$runId;
    }

    protected function swarmIndexKey(string $swarmClass): string
    {
        return (string) $this->config->get('swarm.history.index_prefix', 'swarm:index:').$swarmClass;
    }

    protected function latestIndexKey(): string
    {
        return (string) $this->config->get('swarm.history.latest_prefix', 'swarm:index:latest');
    }

    protected function appendToIndex(string $key, string $runId, int $ttlSeconds): void
    {
        $index = $this->store()->get($key, []);

        if (! is_array($index)) {
            $index = [];
        }

        $index[] = $runId;

        $this->store()->put($key, $index, $ttlSeconds);
    }

    protected function store(): Repository
    {
        return $this->resolveCacheStore($this->cacheFactory, $this->config, 'history');
    }
}
