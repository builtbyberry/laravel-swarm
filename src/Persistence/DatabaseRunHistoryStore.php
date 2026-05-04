<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ClaimsQueuedRunExecution;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\DatabaseTtl;
use BuiltByBerry\LaravelSwarm\Support\PersistedRunContextMatcher;
use BuiltByBerry\LaravelSwarm\Support\QueuedRunAcquisition;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Throwable;

class DatabaseRunHistoryStore implements ClaimsQueuedRunExecution, RunHistoryStore
{
    use InteractsWithJsonColumns;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected SwarmCapture $capture,
        protected SwarmPersistenceCipher $cipher,
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

    public function acquireQueuedRunContinuationLease(string $runId, int $ttlSeconds, int $leaseSeconds): QueuedRunAcquisition
    {
        $this->assertQueueLeaseColumnsPresent();
        $timestamp = Carbon::now('UTC');
        $executionToken = RunContext::newRunId();

        return $this->connection->transaction(function () use ($runId, $ttlSeconds, $leaseSeconds, $timestamp, $executionToken): QueuedRunAcquisition {
            /** @var object|null $record */
            $record = $this->table()->where('run_id', $runId)->lockForUpdate()->first();

            if ($record === null || ($record->status ?? null) !== 'waiting') {
                return QueuedRunAcquisition::duplicateRunning();
            }

            $metadata = $this->decodeJson($record->metadata ?? null, []);

            if (! ($metadata['queue_hierarchical_waiting_parallel'] ?? false)) {
                return QueuedRunAcquisition::duplicateRunning();
            }

            $metadata['queue_hierarchical_waiting_parallel'] = false;

            $this->table()->where('run_id', $runId)->update([
                'status' => 'running',
                'execution_token' => $executionToken,
                'leased_until' => $timestamp->copy()->addSeconds($leaseSeconds),
                'metadata' => $this->encodeJson($metadata),
                'updated_at' => $timestamp,
                'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
            ]);

            return QueuedRunAcquisition::fresh($executionToken);
        });
    }

    public function recordStep(string $runId, SwarmStep $step, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        if ($this->hasNormalizedStepTable()) {
            $this->connection->transaction(function () use ($runId, $step, $ttlSeconds, $executionToken, $leaseSeconds): void {
                $updated = $this->update($runId, [
                    'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
                ], $executionToken, $leaseSeconds);

                if ($executionToken !== null && $updated === 0) {
                    throw new LostSwarmLeaseException("Queued swarm run [{$runId}] no longer owns the execution lease.");
                }

                $payload = $this->stepPayload($runId, $step, $ttlSeconds);

                $this->stepTable()->upsert(
                    [$payload],
                    ['run_id', 'step_index'],
                    ['agent_class', 'input', 'output', 'artifacts', 'metadata', 'expires_at', 'updated_at'],
                );
            });

            return;
        }

        $history = $this->find($runId) ?? [];
        $history['steps'] ??= [];
        $history['steps'][] = $step->toArray();

        $updated = $this->update($runId, [
            'steps' => $this->encodeJson(array_map(
                fn (array $storedStep): array => $this->cipher->sealStepIo($storedStep),
                $history['steps'],
            )),
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
            'output' => $this->cipher->seal($response->output),
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
                'message' => $this->capture->failureMessage($exception),
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

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function syncDurableState(string $runId, string $status, RunContext $context, array $metadata, int $ttlSeconds, bool $finished, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $values = [
            'status' => $status,
            'context' => $this->encodeJson($this->cipher->sealContextTopLevelInput($context->toArray())),
            'metadata' => $this->encodeJson($metadata),
            'artifacts' => $this->encodeJson(array_map(static fn ($artifact): array => $artifact->toArray(), $context->artifacts)),
            'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
        ];

        if ($finished) {
            $values['finished_at'] = Carbon::now('UTC');
        }

        if ($executionToken === null) {
            $values['execution_token'] = null;
            $values['leased_until'] = null;
            $this->update($runId, $values);

            return;
        }

        if ($status === 'running') {
            $values['execution_token'] = $executionToken;
            $values['leased_until'] = Carbon::now('UTC')->addSeconds($leaseSeconds ?? 0);
            $this->update($runId, $values);

            return;
        }

        $values['execution_token'] = null;
        $values['leased_until'] = null;

        $updated = $this->update($runId, $values, $executionToken, $leaseSeconds);

        if ($updated === 0) {
            throw new LostSwarmLeaseException("Durable swarm run [{$runId}] no longer owns the execution lease.");
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

        if ($contextSubset !== null && ! $this->cipher->enabled()) {
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
            $query->whereNotNull('leased_until');
            $query->where('leased_until', '>=', $timestamp);

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

    protected function stepTable()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.history_steps', 'swarm_run_steps'));
    }

    protected function mapRecord(object $record): array
    {
        $steps = $this->stepsForRecord($record);

        return [
            'run_id' => $record->run_id,
            'swarm_class' => $record->swarm_class,
            'topology' => $record->topology,
            'status' => $record->status,
            'context' => $this->cipher->openContextTopLevelInput($this->decodeJson($record->context, [])),
            'metadata' => $this->decodeJson($record->metadata, []),
            'steps' => $steps,
            'output' => $record->output !== null ? $this->cipher->open((string) $record->output) : null,
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
            'context' => $this->encodeJson($this->cipher->sealContextTopLevelInput($context->toArray())),
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

    public function assertReady(): void
    {
        $table = (string) $this->config->get('swarm.tables.history', 'swarm_run_histories');
        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            throw new SwarmException("Database-backed durable swarms require the [{$table}] table.");
        }

        if (! $schema->hasColumns($table, [
            'run_id',
            'swarm_class',
            'topology',
            'status',
            'context',
            'metadata',
            'steps',
            'output',
            'usage',
            'error',
            'artifacts',
            'finished_at',
            'created_at',
            'updated_at',
            'expires_at',
            'execution_token',
            'leased_until',
        ])) {
            throw new SwarmException("Database-backed durable swarms require runtime columns on [{$table}] for persisted run history.");
        }

        $stepsTable = (string) $this->config->get('swarm.tables.history_steps', 'swarm_run_steps');

        if (! $schema->hasTable($stepsTable)) {
            throw new SwarmException("Database-backed durable swarms require the [{$stepsTable}] table.");
        }

        if (! $schema->hasColumns($stepsTable, [
            'id',
            'run_id',
            'step_index',
            'agent_class',
            'input',
            'output',
            'artifacts',
            'metadata',
            'created_at',
            'updated_at',
            'expires_at',
        ])) {
            throw new SwarmException("Database-backed durable swarms require runtime columns on [{$stepsTable}] for normalized run steps.");
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

    protected function hasNormalizedStepTable(): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable((string) $this->config->get('swarm.tables.history_steps', 'swarm_run_steps'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function normalizedSteps(string $runId): array
    {
        if (! $this->hasNormalizedStepTable()) {
            return [];
        }

        return $this->stepTable()
            ->where('run_id', $runId)
            ->orderBy('step_index')
            ->orderBy('id')
            ->get()
            ->map(fn (object $record): array => [
                'step_index' => (int) $record->step_index,
                'agent_class' => $record->agent_class,
                'input' => $this->cipher->open((string) $record->input),
                'output' => $this->cipher->open((string) $record->output),
                'artifacts' => $this->decodeJson($record->artifacts, []),
                'metadata' => $this->decodeJson($record->metadata, []),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function stepPayload(string $runId, SwarmStep $step, int $ttlSeconds): array
    {
        $timestamp = Carbon::now('UTC');
        $payload = $this->cipher->sealStepIo($step->toArray());
        $stepIndex = $step->metadata['index'] ?? null;

        if (! is_int($stepIndex)) {
            throw new SwarmException('Normalized database run history steps require an integer [index] metadata value.');
        }

        return [
            'run_id' => $runId,
            'step_index' => $stepIndex,
            'agent_class' => $payload['agent_class'],
            'input' => $payload['input'],
            'output' => $payload['output'],
            'artifacts' => $this->encodeJson($payload['artifacts']),
            'metadata' => $this->encodeJson($payload['metadata']),
            'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function stepsForRecord(object $record): array
    {
        $steps = [];

        foreach ($this->decodeJson($record->steps, []) as $step) {
            if (! is_array($step)) {
                continue;
            }

            $steps[$this->stepSortIndex($step, count($steps))] = $this->cipher->openStepIo($step);
        }

        foreach ($this->normalizedSteps($record->run_id) as $step) {
            $stepIndex = $step['step_index'];
            unset($step['step_index']);

            $steps[$stepIndex] = $step;
        }

        ksort($steps);

        return array_values($steps);
    }

    /**
     * @param  array<string, mixed>  $step
     */
    protected function stepSortIndex(array $step, int $fallback): int
    {
        $index = $step['metadata']['index'] ?? null;

        return is_int($index) ? $index : $fallback;
    }
}
