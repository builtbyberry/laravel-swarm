<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Support\DatabaseTtl;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

class DatabaseDurableRunStore implements DurableRunStore
{
    use InteractsWithJsonColumns;

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
            'execution_mode' => $record->execution_mode ?? 'durable',
            'status' => $record->status,
            'next_step_index' => (int) $record->next_step_index,
            'current_step_index' => $record->current_step_index !== null ? (int) $record->current_step_index : null,
            'total_steps' => (int) $record->total_steps,
            'route_plan' => $this->decodeJson($record->route_plan, null),
            'route_cursor' => $this->decodeJson($record->route_cursor, null),
            'route_start_node_id' => $record->route_start_node_id ?? null,
            'current_node_id' => $record->current_node_id ?? null,
            'completed_node_ids' => $this->decodeJson($record->completed_node_ids ?? null, []),
            'node_states' => $this->decodeJson($record->node_states ?? null, []),
            'failure' => $this->decodeJson($record->failure ?? null, null),
            'timeout_at' => $record->timeout_at,
            'step_timeout_seconds' => (int) $record->step_timeout_seconds,
            'attempts' => (int) ($record->attempts ?? 0),
            'lease_acquired_at' => $record->lease_acquired_at ?? null,
            'execution_token' => $record->execution_token,
            'leased_until' => $record->leased_until,
            'recovery_count' => (int) ($record->recovery_count ?? 0),
            'last_recovered_at' => $record->last_recovered_at ?? null,
            'pause_requested_at' => $record->pause_requested_at,
            'paused_at' => $record->paused_at ?? null,
            'resumed_at' => $record->resumed_at ?? null,
            'cancel_requested_at' => $record->cancel_requested_at,
            'cancelled_at' => $record->cancelled_at ?? null,
            'timed_out_at' => $record->timed_out_at ?? null,
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
            'execution_mode',
            'status',
            'next_step_index',
            'current_step_index',
            'total_steps',
            'route_plan',
            'route_cursor',
            'route_start_node_id',
            'current_node_id',
            'completed_node_ids',
            'node_states',
            'failure',
            'timeout_at',
            'step_timeout_seconds',
            'attempts',
            'lease_acquired_at',
            'execution_token',
            'leased_until',
            'recovery_count',
            'last_recovered_at',
            'pause_requested_at',
            'paused_at',
            'resumed_at',
            'cancel_requested_at',
            'cancelled_at',
            'timed_out_at',
            'queue_connection',
            'queue_name',
            'finished_at',
            'created_at',
            'updated_at',
        ];

        if (! $schema->hasColumns($table, $requiredColumns)) {
            throw new SwarmException("Database-backed durable swarms require runtime columns on [{$table}] for lease ownership and recovery.");
        }

        $nodeOutputTable = (string) $this->config->get('swarm.tables.durable_node_outputs', 'swarm_durable_node_outputs');

        if (! $schema->hasTable($nodeOutputTable)) {
            throw new SwarmException("Database-backed durable swarms require the [{$nodeOutputTable}] table.");
        }

        if (! $schema->hasColumns($nodeOutputTable, ['run_id', 'node_id', 'output', 'created_at', 'updated_at', 'expires_at'])) {
            throw new SwarmException("Database-backed durable swarms require runtime columns on [{$nodeOutputTable}] for hierarchical node outputs.");
        }

        $branchTable = (string) $this->config->get('swarm.tables.durable_branches', 'swarm_durable_branches');

        if (! $schema->hasTable($branchTable)) {
            throw new SwarmException("Database-backed durable swarms require the [{$branchTable}] table.");
        }

        if (! $schema->hasColumns($branchTable, [
            'run_id',
            'branch_id',
            'step_index',
            'node_id',
            'agent_class',
            'parent_node_id',
            'status',
            'input',
            'output',
            'usage',
            'metadata',
            'failure',
            'duration_ms',
            'execution_token',
            'lease_acquired_at',
            'leased_until',
            'attempts',
            'queue_connection',
            'queue_name',
            'started_at',
            'finished_at',
            'expires_at',
            'created_at',
            'updated_at',
        ])) {
            throw new SwarmException("Database-backed durable swarms require runtime columns on [{$branchTable}] for durable branch execution.");
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
                'lease_acquired_at' => $now,
                'execution_token' => $token,
                'attempts' => $this->connection->raw('attempts + 1'),
                'updated_at' => $now,
            ]);

        if ($acquired !== 1) {
            return null;
        }

        $this->recordNodeState($runId, $token, $expectedStepIndex, [
            'status' => 'leased',
            'attempts' => $this->find($runId)['attempts'] ?? 1,
            'lease_acquired_at' => $now->toJSON(),
            'leased_until' => $now->copy()->addSeconds($stepTimeoutSeconds)->toJSON(),
        ]);

        return $token;
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
        $now = Carbon::now('UTC');
        $nodeId = $this->currentNodeIdFor($runId, $currentStepIndex);

        $this->guardedUpdate($runId, $executionToken, [
            'status' => 'running',
            'current_step_index' => $currentStepIndex,
            'current_node_id' => $nodeId,
            'node_states' => $this->encodeJson($this->mergeNodeState($runId, $nodeId, [
                'status' => 'running',
                'step_index' => $currentStepIndex,
                'started_at' => $now->toJSON(),
            ])),
        ]);
    }

    public function releaseForNextStep(string $runId, string $executionToken, int $nextStepIndex): void
    {
        $run = $this->find($runId);
        $nodeId = $run !== null ? $this->nodeIdForRun($run, (int) ($run['current_step_index'] ?? max($nextStepIndex - 1, 0))) : 'step:'.max($nextStepIndex - 1, 0);
        $completedNodeIds = $this->completedNodeIdsWith($run, $nodeId);

        $this->guardedUpdate($runId, $executionToken, [
            'status' => 'pending',
            'next_step_index' => $nextStepIndex,
            'completed_node_ids' => $this->encodeJson($completedNodeIds),
            'node_states' => $this->encodeJson($this->mergeNodeState($runId, $nodeId, [
                'status' => 'completed',
                'finished_at' => Carbon::now('UTC')->toJSON(),
            ], $run)),
            'execution_token' => null,
            'leased_until' => null,
        ]);
    }

    public function waitForBranches(string $runId, string $executionToken, int $nextStepIndex, string $parentNodeId, RunContext $context, int $ttlSeconds, array $routeCursor = [], ?array $routePlan = null, ?int $totalSteps = null, array $branches = []): void
    {
        $this->connection->transaction(function () use ($runId, $executionToken, $nextStepIndex, $parentNodeId, $context, $ttlSeconds, $routeCursor, $routePlan, $totalSteps, $branches): void {
            $timestamp = Carbon::now('UTC');
            $expiresAt = DatabaseTtl::expiresAt($ttlSeconds);
            $contextPayload = $context->toArray();

            $this->contextTable()->upsert([
                [
                    'run_id' => $contextPayload['run_id'],
                    'input' => $contextPayload['input'],
                    'data' => $this->encodeJson($contextPayload['data']),
                    'metadata' => $this->encodeJson($contextPayload['metadata']),
                    'artifacts' => $this->encodeJson($contextPayload['artifacts']),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'expires_at' => $expiresAt,
                ],
            ], ['run_id'], ['input', 'data', 'metadata', 'artifacts', 'updated_at', 'expires_at']);

            $run = $this->find($runId);
            $values = [
                'status' => 'waiting',
                'next_step_index' => $nextStepIndex,
                'current_step_index' => null,
                'current_node_id' => $parentNodeId,
                'node_states' => $this->encodeJson($this->mergeNodeState($runId, $parentNodeId, [
                    'status' => 'waiting',
                    'step_index' => $nextStepIndex,
                    'started_at' => $timestamp->toJSON(),
                ], $run)),
                'execution_token' => null,
                'leased_until' => null,
            ];

            if ($routeCursor !== []) {
                $values['route_cursor'] = $this->encodeJson($routeCursor);
                $values['route_start_node_id'] = $routeCursor['route_plan_start'] ?? null;
                $values['completed_node_ids'] = $this->encodeJson($routeCursor['completed_node_ids'] ?? []);
            }

            if ($routePlan !== null) {
                $values['route_plan'] = $this->encodeJson($routePlan);
            }

            if ($totalSteps !== null) {
                $values['total_steps'] = $totalSteps;
            }

            if ($branches !== []) {
                $this->insertBranchRows($runId, $branches, $timestamp, $expiresAt);
            }

            $this->guardedUpdate($runId, $executionToken, $values);
        });
    }

    public function releaseWaitingRunForJoin(string $runId, int $nextStepIndex): bool
    {
        $timestamp = Carbon::now('UTC');

        return $this->table()
            ->where('run_id', $runId)
            ->where('status', 'waiting')
            ->where('next_step_index', $nextStepIndex)
            ->whereNull('finished_at')
            ->update([
                'status' => 'pending',
                'updated_at' => $timestamp,
            ]) === 1;
    }

    public function hierarchicalNodeOutputsFor(string $runId, array $nodeIds): array
    {
        $nodeIds = array_values(array_unique(array_filter($nodeIds, static fn (string $nodeId): bool => $nodeId !== '')));

        if ($nodeIds === []) {
            return [];
        }

        return $this->nodeOutputTable()
            ->where('run_id', $runId)
            ->whereIn('node_id', $nodeIds)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(static fn (object $record): array => [(string) $record->node_id => (string) $record->output])
            ->all();
    }

    public function storeHierarchicalNodeOutput(string $runId, string $nodeId, string $output, int $ttlSeconds): void
    {
        $timestamp = Carbon::now('UTC');

        $this->nodeOutputTable()->upsert([
            [
                'run_id' => $runId,
                'node_id' => $nodeId,
                'output' => $output,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
            ],
        ], ['run_id', 'node_id'], ['output', 'updated_at', 'expires_at']);
    }

    public function checkpointHierarchicalStep(
        string $runId,
        string $executionToken,
        int $nextStepIndex,
        RunContext $context,
        int $ttlSeconds,
        array $routeCursor,
        ?array $routePlan = null,
        ?array $nodeOutput = null,
        ?int $totalSteps = null,
    ): void {
        $this->connection->transaction(function () use ($runId, $executionToken, $nextStepIndex, $context, $ttlSeconds, $routeCursor, $routePlan, $nodeOutput, $totalSteps): void {
            $timestamp = Carbon::now('UTC');
            $expiresAt = DatabaseTtl::expiresAt($ttlSeconds);

            if ($nodeOutput !== null) {
                $this->nodeOutputTable()->upsert([
                    [
                        'run_id' => $runId,
                        'node_id' => $nodeOutput['node_id'],
                        'output' => $nodeOutput['output'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                        'expires_at' => $expiresAt,
                    ],
                ], ['run_id', 'node_id'], ['output', 'updated_at', 'expires_at']);
            }

            $contextPayload = $context->toArray();
            $this->contextTable()->upsert([
                [
                    'run_id' => $contextPayload['run_id'],
                    'input' => $contextPayload['input'],
                    'data' => $this->encodeJson($contextPayload['data']),
                    'metadata' => $this->encodeJson($contextPayload['metadata']),
                    'artifacts' => $this->encodeJson($contextPayload['artifacts']),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'expires_at' => $expiresAt,
                ],
            ], ['run_id'], ['input', 'data', 'metadata', 'artifacts', 'updated_at', 'expires_at']);

            $values = [
                'status' => 'pending',
                'next_step_index' => $nextStepIndex,
                'route_cursor' => $this->encodeJson($routeCursor),
                'route_start_node_id' => $routeCursor['route_plan_start'] ?? null,
                'current_node_id' => $routeCursor['current_node_id'] ?? null,
                'completed_node_ids' => $this->encodeJson($routeCursor['completed_node_ids'] ?? []),
                'execution_token' => null,
                'leased_until' => null,
            ];

            $run = $this->find($runId);
            $completedNodeId = $nodeOutput['node_id'] ?? ($nextStepIndex === 1 ? 'coordinator' : null);

            if (is_string($completedNodeId)) {
                $values['node_states'] = $this->encodeJson($this->mergeNodeState($runId, $completedNodeId, [
                    'status' => 'completed',
                    'finished_at' => $timestamp->toJSON(),
                ], $run));
            }

            if ($routePlan !== null) {
                $values['route_plan'] = $this->encodeJson($routePlan);
            }

            if ($totalSteps !== null) {
                $values['total_steps'] = $totalSteps;
            }

            $this->guardedUpdate($runId, $executionToken, $values);
        });
    }

    public function createBranches(string $runId, array $branches, int $ttlSeconds): void
    {
        if ($branches === []) {
            return;
        }

        $timestamp = Carbon::now('UTC');
        $expiresAt = DatabaseTtl::expiresAt($ttlSeconds);
        $this->insertBranchRows($runId, $branches, $timestamp, $expiresAt);
    }

    public function findBranch(string $runId, string $branchId): ?array
    {
        /** @var object|null $record */
        $record = $this->branchTable()
            ->where('run_id', $runId)
            ->where('branch_id', $branchId)
            ->first();

        return $record !== null ? $this->mapBranch($record) : null;
    }

    public function branchesFor(string $runId, ?string $parentNodeId = null): array
    {
        $query = $this->branchTable()
            ->where('run_id', $runId)
            ->orderBy('step_index')
            ->orderBy('id');

        if ($parentNodeId !== null) {
            $query->where('parent_node_id', $parentNodeId);
        }

        return $query->get()
            ->map(fn (object $record): array => $this->mapBranch($record))
            ->all();
    }

    public function acquireBranchLease(string $runId, string $branchId, int $stepTimeoutSeconds): ?string
    {
        $token = RunContext::newRunId();
        $now = Carbon::now('UTC');

        $acquired = $this->branchTable()
            ->where('run_id', $runId)
            ->where('branch_id', $branchId)
            ->where(function ($query) use ($now): void {
                $query->whereNull('leased_until')
                    ->orWhere('leased_until', '<', $now);
            })
            ->whereIn('status', ['pending', 'running'])
            ->update([
                'leased_until' => $now->copy()->addSeconds($stepTimeoutSeconds),
                'lease_acquired_at' => $now,
                'execution_token' => $token,
                'attempts' => $this->connection->raw('attempts + 1'),
                'updated_at' => $now,
            ]);

        return $acquired === 1 ? $token : null;
    }

    public function assertBranchOwned(string $runId, string $branchId, string $executionToken): void
    {
        $now = Carbon::now('UTC');

        $owned = $this->branchTable()
            ->where('run_id', $runId)
            ->where('branch_id', $branchId)
            ->where('execution_token', $executionToken)
            ->whereNotNull('leased_until')
            ->where('leased_until', '>=', $now)
            ->exists();

        if (! $owned) {
            throw new LostDurableLeaseException("Durable branch [{$branchId}] for run [{$runId}] no longer owns the execution lease.");
        }
    }

    public function markBranchRunning(string $runId, string $branchId, string $executionToken): void
    {
        $this->guardedBranchUpdate($runId, $branchId, $executionToken, [
            'status' => 'running',
            'started_at' => Carbon::now('UTC'),
        ]);
    }

    public function markBranchCompleted(string $runId, string $branchId, string $executionToken, string $output, array $usage, int $durationMs): void
    {
        $this->guardedBranchUpdate($runId, $branchId, $executionToken, [
            'status' => 'completed',
            'output' => $output,
            'usage' => $this->encodeJson($usage),
            'duration_ms' => $durationMs,
            'failure' => null,
            'finished_at' => Carbon::now('UTC'),
            'execution_token' => null,
            'leased_until' => null,
        ]);
    }

    public function markBranchFailed(string $runId, string $branchId, string $executionToken, array $failure): void
    {
        $this->guardedBranchUpdate($runId, $branchId, $executionToken, [
            'status' => 'failed',
            'failure' => $this->encodeJson($failure),
            'finished_at' => Carbon::now('UTC'),
            'execution_token' => null,
            'leased_until' => null,
        ]);
    }

    public function cancelBranches(string $runId, ?string $parentNodeId = null): void
    {
        $query = $this->branchTable()
            ->where('run_id', $runId)
            ->whereNotIn('status', ['completed', 'failed', 'cancelled']);

        if ($parentNodeId !== null) {
            $query->where('parent_node_id', $parentNodeId);
        }

        $query->update([
            'status' => 'cancelled',
            'finished_at' => Carbon::now('UTC'),
            'execution_token' => null,
            'leased_until' => null,
            'updated_at' => Carbon::now('UTC'),
        ]);
    }

    public function recoverableBranches(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array
    {
        $now = Carbon::now('UTC');
        $threshold = $now->copy()->subSeconds($graceSeconds);

        $query = $this->branchTable()
            ->join($this->config->get('swarm.tables.durable', 'swarm_durable_runs'), 'swarm_durable_branches.run_id', '=', 'swarm_durable_runs.run_id')
            ->whereIn('swarm_durable_branches.status', ['pending', 'running'])
            ->where('swarm_durable_branches.updated_at', '<=', $threshold)
            ->where(function ($query) use ($now): void {
                $query->whereNull('swarm_durable_branches.leased_until')
                    ->orWhere('swarm_durable_branches.leased_until', '<', $now);
            })
            ->whereNull('swarm_durable_runs.finished_at')
            ->orderBy('swarm_durable_branches.updated_at')
            ->limit($limit)
            ->select('swarm_durable_branches.*');

        if ($runId !== null) {
            $query->where('swarm_durable_branches.run_id', $runId);
        }

        if ($swarmClass !== null) {
            $query->where('swarm_durable_runs.swarm_class', $swarmClass);
        }

        return $query->get()
            ->map(fn (object $record): array => $this->mapBranch($record))
            ->all();
    }

    public function markCompleted(string $runId, string $executionToken): void
    {
        $this->markTerminal($runId, $executionToken, 'completed');
    }

    public function markFailed(string $runId, string $executionToken, ?array $failure = null): void
    {
        $this->markTerminal($runId, $executionToken, 'failed', $failure);
    }

    public function markPaused(string $runId, string $executionToken): void
    {
        $run = $this->find($runId);
        $nodeId = $run !== null ? $this->nodeIdForRun($run, (int) ($run['current_step_index'] ?? $run['next_step_index'] ?? 0)) : null;
        $now = Carbon::now('UTC');

        $this->guardedUpdate($runId, $executionToken, [
            'status' => 'paused',
            'paused_at' => $now,
            'node_states' => $this->encodeJson($this->mergeNodeState($runId, $nodeId, [
                'status' => 'paused',
                'finished_at' => $now->toJSON(),
            ], $run)),
            'execution_token' => null,
            'leased_until' => null,
        ]);
    }

    public function markCancelled(string $runId, string $executionToken): void
    {
        $this->markTerminal($runId, $executionToken, 'cancelled');
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
                'paused_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated === 1) {
            return true;
        }

        return $this->table()
            ->where('run_id', $runId)
            ->whereIn('status', ['running', 'waiting'])
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
                'resumed_at' => Carbon::now('UTC'),
                'updated_at' => Carbon::now('UTC'),
            ]) === 1;
    }

    public function cancel(string $runId): bool
    {
        $now = Carbon::now('UTC');

        $updated = $this->connection->transaction(function () use ($runId, $now): int {
            $routePlanProjection = $this->terminalRoutePlanProjection($this->find($runId));

            $updated = $this->table()
                ->where('run_id', $runId)
                ->whereIn('status', ['pending', 'paused'])
                ->update([
                    'status' => 'cancelled',
                    'cancel_requested_at' => $now,
                    'cancelled_at' => $now,
                    'finished_at' => $now,
                    'execution_token' => null,
                    'leased_until' => null,
                    'route_plan' => $routePlanProjection !== null ? $this->encodeJson($routePlanProjection) : null,
                    'updated_at' => $now,
                ]);

            if ($updated === 1) {
                $this->cancelBranches($runId);
                $this->nodeOutputTable()->where('run_id', $runId)->delete();
            }

            return $updated;
        });

        if ($updated === 1) {
            return true;
        }

        return $this->table()
            ->where('run_id', $runId)
            ->whereIn('status', ['running', 'waiting'])
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

        return $query->get()->map(fn (object $record): ?array => $this->find($record->run_id))->filter()->values()->all();
    }

    public function markRecoveryDispatched(string $runId): void
    {
        $timestamp = Carbon::now('UTC');

        $this->table()
            ->where('run_id', $runId)
            ->whereIn('status', ['pending', 'running'])
            ->whereNull('finished_at')
            ->update([
                'recovery_count' => $this->connection->raw('recovery_count + 1'),
                'last_recovered_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
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

    protected function guardedBranchUpdate(string $runId, string $branchId, string $executionToken, array $values): void
    {
        $now = Carbon::now('UTC');
        $values['updated_at'] = $now;

        $updated = $this->branchTable()
            ->where('run_id', $runId)
            ->where('branch_id', $branchId)
            ->where('execution_token', $executionToken)
            ->whereNotNull('leased_until')
            ->where('leased_until', '>=', $now)
            ->update($values);

        if ($updated === 0) {
            throw new LostDurableLeaseException("Durable branch [{$branchId}] for run [{$runId}] no longer owns the execution lease.");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     */
    protected function insertBranchRows(string $runId, array $branches, Carbon $timestamp, ?Carbon $expiresAt): void
    {
        $rows = [];

        foreach ($branches as $branch) {
            $rows[] = [
                'run_id' => $runId,
                'branch_id' => $branch['branch_id'],
                'step_index' => $branch['step_index'],
                'node_id' => $branch['node_id'] ?? null,
                'agent_class' => $branch['agent_class'],
                'parent_node_id' => $branch['parent_node_id'] ?? null,
                'status' => 'pending',
                'input' => $branch['input'],
                'output' => null,
                'usage' => null,
                'metadata' => $this->encodeJson($branch['metadata'] ?? []),
                'failure' => null,
                'duration_ms' => null,
                'execution_token' => null,
                'lease_acquired_at' => null,
                'leased_until' => null,
                'attempts' => 0,
                'queue_connection' => $branch['queue_connection'] ?? null,
                'queue_name' => $branch['queue_name'] ?? null,
                'started_at' => null,
                'finished_at' => null,
                'expires_at' => $expiresAt,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $this->branchTable()->upsert(
            $rows,
            ['run_id', 'branch_id'],
            ['status', 'input', 'metadata', 'queue_connection', 'queue_name', 'expires_at', 'updated_at'],
        );
    }

    /**
     * @param  array{message: string, class: class-string<\Throwable>, timed_out?: bool}|null  $failure
     */
    protected function markTerminal(string $runId, string $executionToken, string $status, ?array $failure = null): void
    {
        $this->connection->transaction(function () use ($runId, $executionToken, $status, $failure): void {
            $run = $this->find($runId);
            $now = Carbon::now('UTC');
            $nodeId = $run !== null ? $this->nodeIdForRun($run, (int) ($run['current_step_index'] ?? $run['next_step_index'] ?? 0)) : null;
            $routePlanProjection = $this->terminalRoutePlanProjection($run);

            $values = [
                'status' => $status,
                'execution_token' => null,
                'leased_until' => null,
                'route_plan' => $routePlanProjection !== null ? $this->encodeJson($routePlanProjection) : null,
                'finished_at' => $now,
            ];

            if ($status === 'cancelled') {
                $values['cancelled_at'] = $now;
            }

            if ($failure !== null) {
                $values['failure'] = $this->encodeJson($failure);
            }

            if ($status === 'failed' && ($failure['timed_out'] ?? false) === true) {
                $values['timed_out_at'] = $now;
            }

            if ($nodeId !== null) {
                if ($status === 'completed') {
                    $values['completed_node_ids'] = $this->encodeJson($this->completedNodeIdsWith($run, $nodeId));
                }

                $values['node_states'] = $this->encodeJson($this->mergeNodeState($runId, $nodeId, [
                    'status' => $status,
                    'finished_at' => $now->toJSON(),
                    'failure' => $failure,
                ], $run));
            }

            $this->guardedUpdate($runId, $executionToken, $values);

            if (in_array($status, ['failed', 'cancelled'], true)) {
                $this->cancelBranches($runId);
            }

            $this->nodeOutputTable()->where('run_id', $runId)->delete();
        });
    }

    /**
     * @param  array<string, mixed>|null  $run
     * @return array<string, mixed>|null
     */
    protected function terminalRoutePlanProjection(?array $run): ?array
    {
        $plan = $run['route_plan'] ?? null;

        if (! is_array($plan)) {
            return null;
        }

        $nodes = $plan['nodes'] ?? null;

        if (! is_array($nodes)) {
            return [
                'start_at' => $plan['start_at'] ?? null,
                'nodes' => [],
            ];
        }

        $projectedNodes = [];

        foreach ($nodes as $nodeId => $node) {
            if (! is_string($nodeId) || ! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? null;

            if (! is_string($type)) {
                continue;
            }

            $payload = ['type' => $type];

            if ($type === 'worker') {
                $payload = array_merge($payload, array_filter([
                    'agent' => $node['agent'] ?? null,
                    'with_outputs' => $node['with_outputs'] ?? null,
                    'next' => $node['next'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== []));
            } elseif ($type === 'parallel') {
                $payload = array_merge($payload, array_filter([
                    'branches' => $node['branches'] ?? null,
                    'next' => $node['next'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== []));
            } elseif ($type === 'finish') {
                $payload = array_merge($payload, array_filter([
                    'output_from' => $node['output_from'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== []));
            }

            $projectedNodes[$nodeId] = $payload;
        }

        return [
            'start_at' => $plan['start_at'] ?? null,
            'nodes' => $projectedNodes,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function recordNodeState(string $runId, string $executionToken, int $stepIndex, array $state): void
    {
        $run = $this->find($runId);

        if ($run === null) {
            return;
        }

        $nodeId = $this->nodeIdForRun($run, $stepIndex);
        $this->guardedUpdate($runId, $executionToken, [
            'current_node_id' => $nodeId,
            'node_states' => $this->encodeJson($this->mergeNodeState($runId, $nodeId, array_merge([
                'step_index' => $stepIndex,
            ], $state), $run)),
        ]);
    }

    protected function currentNodeIdFor(string $runId, int $stepIndex): string
    {
        return $this->nodeIdForRun($this->find($runId), $stepIndex);
    }

    /**
     * @param  array<string, mixed>|null  $run
     */
    protected function nodeIdForRun(?array $run, int $stepIndex): string
    {
        if ($stepIndex === 0 && ($run['topology'] ?? null) === 'hierarchical') {
            return 'coordinator';
        }

        $cursor = $run['route_cursor'] ?? null;

        if (is_array($cursor) && is_string($cursor['current_node_id'] ?? null)) {
            return $cursor['current_node_id'];
        }

        return 'step:'.$stepIndex;
    }

    /**
     * @param  array<string, mixed>|null  $run
     * @return array<int, string>
     */
    protected function completedNodeIdsWith(?array $run, string $nodeId): array
    {
        $completed = is_array($run['completed_node_ids'] ?? null) ? $run['completed_node_ids'] : [];
        $completed[] = $nodeId;

        return array_values(array_unique(array_filter($completed, 'is_string')));
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>|null  $run
     * @return array<string, array<string, mixed>>
     */
    protected function mergeNodeState(string $runId, string $nodeId, array $state, ?array $run = null): array
    {
        $run ??= $this->find($runId);
        $states = is_array($run['node_states'] ?? null) ? $run['node_states'] : [];
        $existing = is_array($states[$nodeId] ?? null) ? $states[$nodeId] : [];

        $states[$nodeId] = array_filter(array_merge($existing, ['node_id' => $nodeId], $state), static fn (mixed $value): bool => $value !== null);

        return $states;
    }

    protected function table()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs'));
    }

    protected function contextTable()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.contexts', 'swarm_contexts'));
    }

    protected function nodeOutputTable()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.durable_node_outputs', 'swarm_durable_node_outputs'));
    }

    protected function branchTable()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.durable_branches', 'swarm_durable_branches'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapBranch(object $record): array
    {
        return [
            'run_id' => $record->run_id,
            'branch_id' => $record->branch_id,
            'step_index' => (int) $record->step_index,
            'node_id' => $record->node_id,
            'agent_class' => $record->agent_class,
            'parent_node_id' => $record->parent_node_id,
            'status' => $record->status,
            'input' => (string) $record->input,
            'output' => $record->output !== null ? (string) $record->output : null,
            'usage' => $this->decodeJson($record->usage ?? null, []),
            'metadata' => $this->decodeJson($record->metadata ?? null, []),
            'failure' => $this->decodeJson($record->failure ?? null, null),
            'duration_ms' => $record->duration_ms !== null ? (int) $record->duration_ms : null,
            'execution_token' => $record->execution_token,
            'lease_acquired_at' => $record->lease_acquired_at ?? null,
            'leased_until' => $record->leased_until ?? null,
            'attempts' => (int) ($record->attempts ?? 0),
            'queue_connection' => $record->queue_connection,
            'queue_name' => $record->queue_name,
            'started_at' => $record->started_at ?? null,
            'finished_at' => $record->finished_at ?? null,
            'expires_at' => $record->expires_at ?? null,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }
}
