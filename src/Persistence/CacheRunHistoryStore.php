<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\RecordsCitationSteps;
use BuiltByBerry\LaravelSwarm\Contracts\RecordsContextualRunFailure;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\ResolvesSwarmCacheStore;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
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

/**
 * @internal
 */
class CacheRunHistoryStore implements ReadableRunHistoryStore, RecordsCitationSteps, RecordsContextualRunFailure, RunHistoryStore
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
            'context' => $this->capture->omitSkippedHistoryContextKeys($context->toArray(), $context),
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
        $this->persistStep($runId, $step, $ttlSeconds, $executionToken, $leaseSeconds);
    }

    public function recordStepWithContext(string $runId, SwarmStep $step, int $ttlSeconds, ?string $executionToken, ?int $leaseSeconds, RunContext $context): void
    {
        $this->persistStep($runId, $step, $ttlSeconds, $executionToken, $leaseSeconds, $context);
    }

    protected function persistStep(string $runId, SwarmStep $step, int $ttlSeconds, ?string $executionToken, ?int $leaseSeconds, ?RunContext $context = null): void
    {
        $history = $this->findRaw($runId) ?? [];
        $history['steps'] ??= [];
        $history['steps'][] = $this->capture->stepToPersistedArray($step, $context);
        $history['updated_at'] = Carbon::now('UTC')->toIso8601String();

        $this->store()->put($this->key($runId), $history, $ttlSeconds);
    }

    public function complete(string $runId, SwarmResponse $response, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $history = $this->findRaw($runId) ?? [];
        $history['status'] = 'completed';
        $history = array_replace($history, $this->capture->citationEvidence($response->citationEvidence, $response->context)->toArray());
        $history['output'] = $this->capture->outputsDecision($response->context) === CaptureDecision::Skip ? null : $response->output;
        $history['usage'] = $response->usage;
        $history['context'] = $response->context !== null
            ? $this->capture->omitSkippedHistoryContextKeys($response->context->toArray(), $response->context)
            : null;
        $history['artifacts'] = collect($response->artifacts)->map(static fn ($artifact): array => $artifact->toArray())->all();
        $history['metadata'] = $response->metadata;
        $history['finished_at'] = Carbon::now('UTC')->toIso8601String();
        $history['updated_at'] = $history['finished_at'];

        $this->store()->put($this->key($runId), $history, $ttlSeconds);
    }

    public function fail(string $runId, Throwable $exception, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $history = $this->findRaw($runId) ?? [];
        $history['status'] = 'failed';
        $history['error'] = $this->failurePayload($exception);
        $history['finished_at'] = Carbon::now('UTC')->toIso8601String();
        $history['updated_at'] = $history['finished_at'];

        $this->store()->put($this->key($runId), $history, $ttlSeconds);
    }

    public function failWithMetadata(string $runId, Throwable $exception, array $metadata, int $ttlSeconds): void
    {
        $history = $this->findRaw($runId) ?? [];
        $history['status'] = 'failed';
        $history['error'] = $this->failurePayload($exception);
        $history['metadata'] = array_replace(is_array($history['metadata'] ?? null) ? $history['metadata'] : [], $metadata);
        $history['finished_at'] = Carbon::now('UTC')->toIso8601String();
        $history['updated_at'] = $history['finished_at'];

        $this->store()->put($this->key($runId), $history, $ttlSeconds);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordPreflightFailure(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, Throwable $exception, int $ttlSeconds): void
    {
        $timestamp = Carbon::now('UTC')->toIso8601String();

        $this->store()->put($this->key($runId), [
            'run_id' => $runId,
            'swarm_class' => $swarmClass,
            'topology' => $topology,
            'status' => 'failed',
            'context' => $this->capture->omitSkippedHistoryContextKeys($context->toArray(), $context),
            'metadata' => $metadata,
            'steps' => [],
            'output' => null,
            'usage' => [],
            'error' => $this->failurePayload($exception),
            'artifacts' => [],
            'started_at' => $timestamp,
            'finished_at' => $timestamp,
            'updated_at' => $timestamp,
        ], $ttlSeconds);

        $this->appendToIndex($this->swarmIndexKey($swarmClass), $runId, $ttlSeconds);
        $this->appendToIndex($this->latestIndexKey(), $runId, $ttlSeconds);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array
    {
        $history = $this->findRaw($runId);

        if ($history !== null) {
            $history += CitationEvidence::fromArray($history)->toArray();
            foreach ($history['steps'] ?? [] as $index => $step) {
                if (is_array($step)) {
                    $history['steps'][$index] = $step + CitationEvidence::fromArray($step)->toArray();
                    $native = match (true) {
                        is_array($history['steps'][$index]['native_result'] ?? null) => $this->capture->nativeResultFromPersistedArray($history['steps'][$index]['native_result']),
                        ($history['steps'][$index]['native_result_status'] ?? null) === NativeStepResult::OMITTED => NativeStepResult::omitted(),
                        array_key_exists('native_result_status', $history['steps'][$index]) => NativeStepResult::unavailable(['missing']),
                        default => NativeStepResult::unavailable(['legacy']),
                    };
                    $history['steps'][$index]['native_result_status'] = $native->status;
                    $history['steps'][$index]['native_result'] = $native->toArray();
                }
            }
        }

        return $history;
    }

    public function findForDisplay(string $runId): ?array
    {
        // The cache driver never seals (encrypt-at-rest is a database-persistence
        // feature), so every field is already plaintext and available — the
        // display read is the plain record with no degrade needed.
        return $this->find($runId);
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

    /**
     * Build the persisted `error` payload. CaptureDecision::Skip omits the
     * `message` key entirely (keeping `class`); Full/Redact keep a `message`.
     *
     * @return array{message?: string, class: class-string<Throwable>}
     */
    protected function failurePayload(Throwable $exception): array
    {
        $message = $this->capture->applyFailureMessage($exception);

        if ($message === null) {
            return ['class' => $exception::class];
        }

        return [
            'message' => $message,
            'class' => $exception::class,
        ];
    }

    protected function key(string $runId): string
    {
        return (string) $this->config->get('swarm.history.prefix', 'swarm:history:').$runId;
    }

    /** @return array<string, mixed>|null */
    private function findRaw(string $runId): ?array
    {
        $history = $this->store()->get($this->key($runId));

        return is_array($history) ? $history : null;
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
