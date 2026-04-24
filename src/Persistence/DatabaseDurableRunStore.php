<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

class DatabaseDurableRunStore implements DurableRunStore
{
    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
    ) {}

    public function create(array $payload): void
    {
        $timestamp = Carbon::now('UTC');

        $this->table()->insert(array_merge($payload, [
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]));
    }

    public function find(string $runId): ?array
    {
        /** @var object|null $record */
        $record = $this->table()->where('run_id', $runId)->first();

        if ($record === null) {
            return null;
        }

        return [
            'run_id' => $record->run_id,
            'swarm_class' => $record->swarm_class,
            'topology' => $record->topology,
            'status' => $record->status,
            'next_step_index' => (int) $record->next_step_index,
            'current_step_index' => $record->current_step_index !== null ? (int) $record->current_step_index : null,
            'total_steps' => (int) $record->total_steps,
            'timeout_at' => $record->timeout_at,
            'step_timeout_seconds' => (int) $record->step_timeout_seconds,
            'execution_token' => $record->execution_token,
            'leased_until' => $record->leased_until,
            'pause_requested_at' => $record->pause_requested_at,
            'cancel_requested_at' => $record->cancel_requested_at,
            'queue_connection' => $record->queue_connection,
            'queue_name' => $record->queue_name,
            'finished_at' => $record->finished_at,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    public function assertReady(): void
    {
        $table = (string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs');
        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            throw new SwarmException("Database-backed durable swarms require the [{$table}] table.");
        }

        $requiredColumns = [
            'run_id',
            'swarm_class',
            'topology',
            'status',
            'next_step_index',
            'current_step_index',
            'total_steps',
            'timeout_at',
            'step_timeout_seconds',
            'execution_token',
            'leased_until',
            'pause_requested_at',
            'cancel_requested_at',
            'queue_connection',
            'queue_name',
            'finished_at',
            'created_at',
            'updated_at',
        ];

        if (! $schema->hasColumns($table, $requiredColumns)) {
            throw new SwarmException("Database-backed durable swarms require runtime columns on [{$table}] for lease ownership and recovery.");
        }
    }

    public function acquireLease(string $runId, int $expectedStepIndex, int $stepTimeoutSeconds): ?string
    {
        $token = RunContext::newRunId();
        $now = Carbon::now('UTC');

        $acquired = $this->table()
            ->where('run_id', $runId)
            ->where('next_step_index', $expectedStepIndex)
            ->where(function ($query) use ($now): void {
                $query->whereNull('leased_until')
                    ->orWhere('leased_until', '<', $now);
            })
            ->whereIn('status', ['pending', 'running'])
            ->update([
                'leased_until' => $now->copy()->addSeconds($stepTimeoutSeconds),
                'execution_token' => $token,
                'updated_at' => $now,
            ]);

        return $acquired === 1 ? $token : null;
    }

    public function assertOwned(string $runId, string $executionToken): void
    {
        $now = Carbon::now('UTC');

        $owned = $this->table()
            ->where('run_id', $runId)
            ->where('execution_token', $executionToken)
            ->whereNotNull('leased_until')
            ->where('leased_until', '>=', $now)
            ->exists();

        if (! $owned) {
            throw new LostDurableLeaseException("Durable swarm run [{$runId}] no longer owns the execution lease.");
        }
    }

    public function markRunning(string $runId, string $executionToken, int $currentStepIndex): void
    {
        $this->guardedUpdate($runId, $executionToken, [
            'status' => 'running',
            'current_step_index' => $currentStepIndex,
        ]);
    }

    public function releaseForNextStep(string $runId, string $executionToken, int $nextStepIndex): void
    {
        $this->guardedUpdate($runId, $executionToken, [
            'status' => 'pending',
            'next_step_index' => $nextStepIndex,
            'execution_token' => null,
            'leased_until' => null,
        ]);
    }

    public function markCompleted(string $runId, string $executionToken): void
    {
        $this->guardedUpdate($runId, $executionToken, [
            'status' => 'completed',
            'execution_token' => null,
            'leased_until' => null,
            'finished_at' => Carbon::now('UTC'),
        ]);
    }

    public function markFailed(string $runId, string $executionToken): void
    {
        $this->guardedUpdate($runId, $executionToken, [
            'status' => 'failed',
            'execution_token' => null,
            'leased_until' => null,
            'finished_at' => Carbon::now('UTC'),
        ]);
    }

    public function markPaused(string $runId, string $executionToken): void
    {
        $this->guardedUpdate($runId, $executionToken, [
            'status' => 'paused',
            'execution_token' => null,
            'leased_until' => null,
        ]);
    }

    public function markCancelled(string $runId, string $executionToken): void
    {
        $this->guardedUpdate($runId, $executionToken, [
            'status' => 'cancelled',
            'execution_token' => null,
            'leased_until' => null,
            'finished_at' => Carbon::now('UTC'),
        ]);
    }

    public function pause(string $runId): bool
    {
        $now = Carbon::now('UTC');

        $updated = $this->table()
            ->where('run_id', $runId)
            ->where('status', 'pending')
            ->whereNull('leased_until')
            ->update([
                'status' => 'paused',
                'pause_requested_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated === 1) {
            return true;
        }

        return $this->table()
            ->where('run_id', $runId)
            ->where('status', 'running')
            ->update([
                'pause_requested_at' => $now,
                'updated_at' => $now,
            ]) === 1;
    }

    public function resume(string $runId): bool
    {
        return $this->table()
            ->where('run_id', $runId)
            ->where('status', 'paused')
            ->update([
                'status' => 'pending',
                'pause_requested_at' => null,
                'updated_at' => Carbon::now('UTC'),
            ]) === 1;
    }

    public function cancel(string $runId): bool
    {
        $now = Carbon::now('UTC');

        $updated = $this->table()
            ->where('run_id', $runId)
            ->whereIn('status', ['pending', 'paused'])
            ->update([
                'status' => 'cancelled',
                'cancel_requested_at' => $now,
                'finished_at' => $now,
                'execution_token' => null,
                'leased_until' => null,
                'updated_at' => $now,
            ]);

        if ($updated === 1) {
            return true;
        }

        return $this->table()
            ->where('run_id', $runId)
            ->where('status', 'running')
            ->update([
                'cancel_requested_at' => $now,
                'updated_at' => $now,
            ]) === 1;
    }

    public function recoverable(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array
    {
        $now = Carbon::now('UTC');
        $threshold = $now->copy()->subSeconds($graceSeconds);

        $query = $this->table()
            ->whereIn('status', ['pending', 'running'])
            ->whereNull('finished_at')
            ->where('updated_at', '<=', $threshold)
            ->where(function ($query) use ($now): void {
                $query->whereNull('leased_until')
                    ->orWhere('leased_until', '<', $now);
            })
            ->orderBy('updated_at')
            ->limit($limit);

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        if ($swarmClass !== null) {
            $query->where('swarm_class', $swarmClass);
        }

        return $query->get()->map(fn (object $record): array => $this->find($record->run_id))->filter()->values()->all();
    }

    public function updateQueueRouting(string $runId, ?string $connection, ?string $queue): void
    {
        $this->table()->where('run_id', $runId)->update([
            'queue_connection' => $connection,
            'queue_name' => $queue,
            'updated_at' => Carbon::now('UTC'),
        ]);
    }

    protected function guardedUpdate(string $runId, string $executionToken, array $values): void
    {
        $now = Carbon::now('UTC');
        $values['updated_at'] = $now;

        $updated = $this->table()
            ->where('run_id', $runId)
            ->where('execution_token', $executionToken)
            ->whereNotNull('leased_until')
            ->where('leased_until', '>=', $now)
            ->update($values);

        if ($updated === 0) {
            throw new LostDurableLeaseException("Durable swarm run [{$runId}] no longer owns the execution lease.");
        }
    }

    protected function table()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs'));
    }
}
