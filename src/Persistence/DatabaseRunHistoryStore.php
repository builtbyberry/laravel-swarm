<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ClaimsQueuedRunExecution;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\DatabaseTtl;
use BuiltByBerry\LaravelSwarm\Support\PersistedRunContextMatcher;
use BuiltByBerry\LaravelSwarm\Support\QueuedRunAcquisition;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Throwable;

class DatabaseRunHistoryStore implements ClaimsQueuedRunExecution, RunHistoryStore
{
    use InteractsWithJsonColumns;

    public function __construct(
        protected ConnectionInterface $connection,
        protected ConfigRepository $config,
    ) {}

    public function start(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, int $ttlSeconds): void
    {
        $timestamp = Carbon::now('UTC');

        $this->table()->updateOrInsert(['run_id' => $runId], $this->startPayload(
            swarmClass: $swarmClass,
            topology: $topology,
            context: $context,
            metadata: $metadata,
            timestamp: $timestamp,
            ttlSeconds: $ttlSeconds,
        ));
    }

    public function acquireQueuedRun(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, int $ttlSeconds, int $leaseSeconds): QueuedRunAcquisition
    {
        $timestamp = Carbon::now('UTC');
        $executionToken = RunContext::newRunId();

        $this->assertQueueLeaseColumnsPresent();

        $inserted = $this->table()->insertOrIgnore(array_merge(
            ['run_id' => $runId],
            $this->queuedStartPayload(
                swarmClass: $swarmClass,
                topology: $topology,
                context: $context,
                metadata: $metadata,
                timestamp: $timestamp,
                ttlSeconds: $ttlSeconds,
                executionToken: $executionToken,
                leaseSeconds: $leaseSeconds,
            ),
        ));

        if ($inserted === 1) {
            return QueuedRunAcquisition::fresh($executionToken);
        }

        $stolen = $this->table()
            ->where('run_id', $runId)
            ->where('status', 'running')
            ->whereNotNull('leased_until')
            ->where('leased_until', '<', $timestamp)
            ->update([
                'execution_token' => $executionToken,
                'leased_until' => $timestamp->copy()->addSeconds($leaseSeconds),
                'updated_at' => $timestamp,
                'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
            ]);

        if ($stolen === 1) {
            return QueuedRunAcquisition::reclaimed($executionToken);
        }

        $record = $this->find($runId);

        if (($record['status'] ?? null) === 'completed') {
            return QueuedRunAcquisition::duplicateCompleted();
        }

        if (($record['status'] ?? null) === 'failed') {
            return QueuedRunAcquisition::duplicateFailed();
        }

        return QueuedRunAcquisition::duplicateRunning();
    }

    public function recordStep(string $runId, SwarmStep $step, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $history = $this->find($runId) ?? [];
        $history['steps'] ??= [];
        $history['steps'][] = $step->toArray();

        $updated = $this->update($runId, [
            'steps' => $this->encodeJson($history['steps']),
            'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
        ], $executionToken, $leaseSeconds);

        if ($executionToken !== null && $updated === 0) {
            throw new LostSwarmLeaseException("Queued swarm run [{$runId}] no longer owns the execution lease.");
        }
    }

    public function complete(string $runId, SwarmResponse $response, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $updated = $this->update($runId, [
            'status' => 'completed',
            'output' => $response->output,
            'usage' => $this->encodeJson($response->usage),
            'context' => $this->encodeJson($response->context?->toArray()),
            'artifacts' => $this->encodeJson(array_map(static fn ($artifact): array => $artifact->toArray(), $response->artifacts)),
            'metadata' => $this->encodeJson($response->metadata),
            'finished_at' => Carbon::now('UTC'),
            'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
            'execution_token' => null,
            'leased_until' => null,
        ], $executionToken, $leaseSeconds);

        if ($executionToken !== null && $updated === 0) {
            throw new LostSwarmLeaseException("Queued swarm run [{$runId}] no longer owns the execution lease.");
        }
    }

    public function fail(string $runId, Throwable $exception, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $updated = $this->update($runId, [
            'status' => 'failed',
            'error' => $this->encodeJson([
                'message' => $exception->getMessage(),
                'class' => $exception::class,
            ]),
            'finished_at' => Carbon::now('UTC'),
            'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
            'execution_token' => null,
            'leased_until' => null,
        ], $executionToken, $leaseSeconds);

        if ($executionToken !== null && $updated === 0) {
            throw new LostSwarmLeaseException("Queued swarm run [{$runId}] no longer owns the execution lease.");
        }
    }

    public function find(string $runId): ?array
    {
        /** @var object|null $record */
        $record = $this->table()->where('run_id', $runId)->first();

        if ($record === null) {
            return null;
        }

        return $this->mapRecord($record);
    }

    public function findMatching(string $swarmClass, ?string $status, ?array $contextSubset): iterable
    {
        $query = $this->table()
            ->where('swarm_class', $swarmClass)
            ->orderByDesc('created_at');

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($contextSubset !== null) {
            $this->applyContextSubsetFilters($query, $contextSubset);
        }

        foreach ($query->cursor() as $record) {
            $mapped = $this->mapRecord($record);

            if ($contextSubset !== null && ! PersistedRunContextMatcher::matchesRecord($contextSubset, $mapped)) {
                continue;
            }

            yield $mapped;
        }
    }

    public function query(?string $swarmClass = null, ?string $status = null, int $limit = 25): array
    {
        $query = $this->table()->orderByDesc('created_at')->limit($limit);

        if ($swarmClass !== null) {
            $query->where('swarm_class', $swarmClass);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->get()
            ->map(fn (object $record): array => $this->mapRecord($record))
            ->all();
    }

    protected function update(string $runId, array $values, ?string $executionToken = null, ?int $leaseSeconds = null): int
    {
        $timestamp = Carbon::now('UTC');
        $values['updated_at'] = $timestamp;

        $query = $this->table()->where('run_id', $runId);

        if ($executionToken !== null) {
            $query->where('execution_token', $executionToken);

            if ($leaseSeconds !== null) {
                $values['leased_until'] = $timestamp->copy()->addSeconds($leaseSeconds);
            }
        }

        return $query->update($values);
    }

    protected function table()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.history', 'swarm_run_histories'));
    }

    protected function mapRecord(object $record): array
    {
        return [
            'run_id' => $record->run_id,
            'swarm_class' => $record->swarm_class,
            'topology' => $record->topology,
            'status' => $record->status,
            'context' => $this->decodeJson($record->context, []),
            'metadata' => $this->decodeJson($record->metadata, []),
            'steps' => $this->decodeJson($record->steps, []),
            'output' => $record->output,
            'usage' => $this->decodeJson($record->usage, []),
            'error' => $this->decodeJson($record->error, null),
            'artifacts' => $this->decodeJson($record->artifacts, []),
            'started_at' => $record->created_at,
            'finished_at' => $record->finished_at,
            'execution_token' => $record->execution_token ?? null,
            'leased_until' => $record->leased_until ?? null,
            'updated_at' => $record->updated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function startPayload(string $swarmClass, string $topology, RunContext $context, array $metadata, Carbon $timestamp, int $ttlSeconds): array
    {
        return [
            'swarm_class' => $swarmClass,
            'topology' => $topology,
            'status' => 'running',
            'context' => $this->encodeJson($context->toArray()),
            'metadata' => $this->encodeJson($metadata),
            'steps' => $this->encodeJson([]),
            'output' => null,
            'usage' => $this->encodeJson([]),
            'error' => null,
            'artifacts' => $this->encodeJson([]),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function queuedStartPayload(
        string $swarmClass,
        string $topology,
        RunContext $context,
        array $metadata,
        Carbon $timestamp,
        int $ttlSeconds,
        string $executionToken,
        int $leaseSeconds,
    ): array {
        return array_merge($this->startPayload($swarmClass, $topology, $context, $metadata, $timestamp, $ttlSeconds), [
            'execution_token' => $executionToken,
            'leased_until' => $timestamp->copy()->addSeconds($leaseSeconds),
        ]);
    }

    protected function assertQueueLeaseColumnsPresent(): void
    {
        $table = (string) $this->config->get('swarm.tables.history', 'swarm_run_histories');

        if (! $this->connection->getSchemaBuilder()->hasColumns($table, ['execution_token', 'leased_until'])) {
            throw new LostSwarmLeaseException('Database-backed queued swarms require [execution_token] and [leased_until] columns on the history table.');
        }
    }

    /**
     * @param  array<string, mixed>  $contextSubset
     */
    protected function applyContextSubsetFilters(Builder $query, array $contextSubset, string $prefix = 'context'): void
    {
        foreach ($contextSubset as $key => $value) {
            if ($prefix === 'context' && $key !== 'input' && $key !== 'metadata') {
                $this->applyContextSubsetFilters($query, [$key => $value], 'context->data');

                continue;
            }

            $path = "{$prefix}->{$key}";

            if (is_array($value)) {
                $this->applyContextSubsetFilters($query, $value, $path);

                continue;
            }

            $query->where($path, $value);
        }
    }
}
