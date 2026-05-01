<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Support\BranchWaitPayload;
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
            'coordination_profile' => $record->coordination_profile ?? CoordinationProfile::StepDurable->value,
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
            'wait_reason' => $record->wait_reason ?? null,
            'waiting_since' => $record->waiting_since ?? null,
            'wait_timeout_at' => $record->wait_timeout_at ?? null,
            'last_progress_at' => $record->last_progress_at ?? null,
            'retry_policy' => $this->decodeJson($record->retry_policy ?? null, null),
            'retry_attempt' => (int) ($record->retry_attempt ?? 0),
            'next_retry_at' => $record->next_retry_at ?? null,
            'parent_run_id' => $record->parent_run_id ?? null,
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
            'coordination_profile',
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
            'wait_reason',
            'waiting_since',
            'wait_timeout_at',
            'last_progress_at',
            'retry_policy',
            'retry_attempt',
            'next_retry_at',
            'parent_run_id',
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
            'last_progress_at',
            'retry_policy',
            'retry_attempt',
            'next_retry_at',
            'expires_at',
            'created_at',
            'updated_at',
        ])) {
            throw new SwarmException("Database-backed durable swarms require runtime columns on [{$branchTable}] for durable branch execution.");
        }

        foreach ([
            'durable_signals' => ['run_id', 'name', 'status', 'payload', 'idempotency_key', 'created_at', 'updated_at'],
            'durable_waits' => ['run_id', 'name', 'status', 'reason', 'timeout_at', 'signal_id', 'outcome', 'metadata', 'created_at', 'updated_at'],
            'durable_labels' => ['run_id', 'key', 'value_type', 'created_at', 'updated_at'],
            'durable_details' => ['run_id', 'details', 'created_at', 'updated_at'],
            'durable_progress' => ['run_id', 'branch_id', 'progress', 'last_progress_at', 'created_at', 'updated_at'],
            'durable_child_runs' => ['parent_run_id', 'child_run_id', 'child_swarm_class', 'wait_name', 'context_payload', 'status', 'output', 'failure', 'dispatched_at', 'terminal_event_dispatched_at', 'created_at', 'updated_at'],
            'durable_webhook_idempotency' => ['scope', 'idempotency_key', 'request_hash', 'status', 'run_id', 'response_payload', 'completed_at', 'created_at', 'updated_at'],
        ] as $role => $columns) {
            $runtimeTable = (string) $this->config->get("swarm.tables.{$role}");

            if (! $schema->hasTable($runtimeTable) || ! $schema->hasColumns($runtimeTable, $columns)) {
                throw new SwarmException("Database-backed durable swarms require the [{$runtimeTable}] table for durable run inspection.");
            }
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
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', $now);
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

    public function waitForBranches(string $runId, BranchWaitPayload $payload): void
    {
        $this->connection->transaction(function () use ($runId, $payload): void {
            $executionToken = $payload->executionToken;
            $nextStepIndex = $payload->nextStepIndex;
            $parentNodeId = $payload->parentNodeId;
            $routeCursor = $payload->routeCursor;
            $routePlan = $payload->routePlan;
            $totalSteps = $payload->totalSteps;
            $branches = $payload->branches;
            $ttlSeconds = $payload->ttlSeconds;
            $timestamp = Carbon::now('UTC');
            $expiresAt = DatabaseTtl::expiresAt($ttlSeconds);
            $contextPayload = $payload->context->toArray();

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
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', $now);
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

    public function scheduleBranchRetry(string $runId, string $branchId, string $executionToken, array $policy, int $attempt, ?\DateTimeInterface $nextRetryAt): void
    {
        $this->guardedBranchUpdate($runId, $branchId, $executionToken, [
            'status' => 'pending',
            'retry_policy' => $this->encodeJson($policy),
            'retry_attempt' => $attempt,
            'next_retry_at' => $nextRetryAt,
            'failure' => null,
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
        $branchTable = (string) $this->config->get('swarm.tables.durable_branches', 'swarm_durable_branches');
        $durableTable = (string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs');

        $query = $this->connection->table($branchTable.' as branches')
            ->join($durableTable.' as runs', 'branches.run_id', '=', 'runs.run_id')
            ->whereIn('branches.status', ['pending', 'running'])
            ->where('branches.updated_at', '<=', $threshold)
            ->whereNull('branches.next_retry_at')
            ->where(function ($query) use ($now): void {
                $query->whereNull('branches.leased_until')
                    ->orWhere('branches.leased_until', '<', $now);
            })
            ->whereNull('runs.finished_at')
            ->orderBy('branches.updated_at')
            ->limit($limit)
            ->select('branches.*');

        if ($runId !== null) {
            $query->where('branches.run_id', $runId);
        }

        if ($swarmClass !== null) {
            $query->where('runs.swarm_class', $swarmClass);
        }

        return $query->get()
            ->map(fn (object $record): array => $this->mapBranch($record))
            ->all();
    }

    public function dueRetryBranches(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        $now = Carbon::now('UTC');
        $branchTable = (string) $this->config->get('swarm.tables.durable_branches', 'swarm_durable_branches');
        $durableTable = (string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs');

        $query = $this->connection->table($branchTable.' as branches')
            ->join($durableTable.' as runs', 'branches.run_id', '=', 'runs.run_id')
            ->where('branches.status', 'pending')
            ->whereNotNull('branches.next_retry_at')
            ->where('branches.next_retry_at', '<=', $now)
            ->whereNull('branches.leased_until')
            ->whereNull('runs.finished_at')
            ->orderBy('branches.next_retry_at')
            ->limit($limit)
            ->select('branches.*');

        if ($runId !== null) {
            $query->where('branches.run_id', $runId);
        }

        if ($swarmClass !== null) {
            $query->where('runs.swarm_class', $swarmClass);
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

    public function scheduleRetry(string $runId, string $executionToken, array $policy, int $attempt, ?\DateTimeInterface $nextRetryAt): void
    {
        $this->guardedUpdate($runId, $executionToken, [
            'status' => 'pending',
            'retry_policy' => $this->encodeJson($policy),
            'retry_attempt' => $attempt,
            'next_retry_at' => $nextRetryAt,
            'failure' => null,
            'execution_token' => null,
            'leased_until' => null,
        ]);
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

        $run = $this->find($runId);
        $nodeId = is_string($run['current_node_id'] ?? null) ? $run['current_node_id'] : null;

        $updated = $this->table()
            ->where('run_id', $runId)
            ->where('status', 'waiting')
            ->whereNull('finished_at')
            ->update([
                'status' => 'paused',
                'pause_requested_at' => $now,
                'paused_at' => $now,
                'node_states' => $this->encodeJson($this->mergeNodeState($runId, $nodeId ?? 'waiting', [
                    'status' => 'paused',
                    'finished_at' => $now->toJSON(),
                ], $run)),
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
                'pause_requested_at' => $now,
                'updated_at' => $now,
            ]) === 1;
    }

    public function resume(string $runId): bool
    {
        $run = $this->find($runId);
        $nodeId = is_string($run['current_node_id'] ?? null) ? $run['current_node_id'] : null;
        $hasBranchBoundary = $nodeId !== null && $this->branchTable()
            ->where('run_id', $runId)
            ->where('parent_node_id', $nodeId)
            ->exists();
        $status = $hasBranchBoundary ? 'waiting' : 'pending';

        return $this->table()
            ->where('run_id', $runId)
            ->where('status', 'paused')
            ->update([
                'status' => $status,
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
                ->whereIn('status', ['pending', 'paused', 'waiting'])
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
            ->where('coordination_profile', '!=', CoordinationProfile::QueueHierarchicalParallel->value)
            ->whereNull('next_retry_at')
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

    public function dueRetries(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        $now = Carbon::now('UTC');

        $query = $this->table()
            ->where('status', 'pending')
            ->whereNull('finished_at')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $now)
            ->whereNull('leased_until')
            ->orderBy('next_retry_at')
            ->limit($limit);

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        if ($swarmClass !== null) {
            $query->where('swarm_class', $swarmClass);
        }

        return $query->get()->map(fn (object $record): ?array => $this->find($record->run_id))->filter()->values()->all();
    }

    public function recoverableWaitingJoins(?string $runId = null, ?string $swarmClass = null, int $limit = 50, int $graceSeconds = 300): array
    {
        $threshold = Carbon::now('UTC')->subSeconds($graceSeconds);

        $query = $this->table()
            ->where('status', 'waiting')
            ->whereNull('finished_at')
            ->whereNotNull('current_node_id')
            ->where('updated_at', '<=', $threshold)
            ->orderBy('updated_at')
            ->limit($limit);

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        if ($swarmClass !== null) {
            $query->where('swarm_class', $swarmClass);
        }

        return $query->get()
            ->map(fn (object $record): ?array => $this->find($record->run_id))
            ->filter(fn (?array $run): bool => $run !== null && $this->branchesAreTerminalForWaitingRun($run))
            ->values()
            ->all();
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

    public function markRetryRecoveryDispatched(string $runId): void
    {
        $timestamp = Carbon::now('UTC');

        $this->table()
            ->where('run_id', $runId)
            ->where('status', 'pending')
            ->whereNull('finished_at')
            ->whereNull('leased_until')
            ->update([
                'next_retry_at' => null,
                'recovery_count' => $this->connection->raw('recovery_count + 1'),
                'last_recovered_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
    }

    public function markBranchRecoveryDispatched(string $runId, string $branchId): void
    {
        $timestamp = Carbon::now('UTC');

        $this->branchTable()
            ->where('run_id', $runId)
            ->where('branch_id', $branchId)
            ->whereIn('status', ['pending', 'running'])
            ->whereNull('next_retry_at')
            ->update([
                'updated_at' => $timestamp,
            ]);
    }

    public function markBranchRetryRecoveryDispatched(string $runId, string $branchId): void
    {
        $timestamp = Carbon::now('UTC');

        $this->branchTable()
            ->where('run_id', $runId)
            ->where('branch_id', $branchId)
            ->where('status', 'pending')
            ->whereNull('leased_until')
            ->update([
                'next_retry_at' => null,
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

    public function updateLabels(string $runId, array $labels): void
    {
        $timestamp = Carbon::now('UTC');
        $rows = [];

        foreach ($this->normalizeLabels($labels) as $key => $value) {
            $rows[] = array_merge([
                'run_id' => $runId,
                'key' => $key,
                'value_type' => $value === null ? 'null' : get_debug_type($value),
                'value_string' => is_string($value) ? $value : null,
                'value_integer' => is_int($value) ? $value : null,
                'value_float' => is_float($value) ? $value : null,
                'value_boolean' => is_bool($value) ? $value : null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        if ($rows !== []) {
            $this->labelTable()->upsert(
                $rows,
                ['run_id', 'key'],
                ['value_type', 'value_string', 'value_integer', 'value_float', 'value_boolean', 'updated_at'],
            );
        }
    }

    public function labels(string $runId): array
    {
        return $this->labelTable()
            ->where('run_id', $runId)
            ->orderBy('key')
            ->get()
            ->mapWithKeys(function (object $record): array {
                $value = match ($record->value_type) {
                    'bool' => (bool) $record->value_boolean,
                    'int' => $record->value_integer !== null ? (int) $record->value_integer : null,
                    'float' => $record->value_float !== null ? (float) $record->value_float : null,
                    'string' => $record->value_string,
                    default => null,
                };

                return [(string) $record->key => $value];
            })
            ->all();
    }

    public function runIdsForLabels(array $labels, int $limit = 50): array
    {
        $normalized = $this->normalizeLabels($labels);

        if ($normalized === []) {
            return [];
        }

        $matches = null;

        foreach ($normalized as $key => $value) {
            $query = $this->labelTable()->where('key', $key);

            match (true) {
                is_string($value) => $query->where('value_string', $value),
                is_int($value) => $query->where('value_integer', $value),
                is_float($value) => $query->where('value_float', $value),
                is_bool($value) => $query->where('value_boolean', $value),
                default => $query->where('value_type', 'null'),
            };

            $runIds = $query->pluck('run_id')->map(static fn (mixed $runId): string => (string) $runId)->all();
            $matches = $matches === null ? $runIds : array_values(array_intersect($matches, $runIds));

            if ($matches === []) {
                return [];
            }
        }

        return array_slice($matches ?? [], 0, $limit);
    }

    public function updateDetails(string $runId, array $details): void
    {
        $timestamp = Carbon::now('UTC');
        $existing = $this->details($runId);

        $this->detailTable()->upsert([
            [
                'run_id' => $runId,
                'details' => $this->encodeJson(array_merge($existing, $details)),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ], ['run_id'], ['details', 'updated_at']);
    }

    public function details(string $runId): array
    {
        /** @var object|null $record */
        $record = $this->detailTable()->where('run_id', $runId)->first();

        return $record !== null ? $this->decodeJson($record->details, []) : [];
    }

    public function recordSignal(string $runId, string $name, mixed $payload = null, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey !== null) {
            /** @var object|null $existing */
            $existing = $this->signalTable()
                ->where('run_id', $runId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return array_merge($this->mapSignal($existing), ['duplicate' => true]);
            }
        }

        $timestamp = Carbon::now('UTC');

        $row = [
            'run_id' => $runId,
            'name' => $name,
            'status' => 'recorded',
            'payload' => $this->encodeJson($payload),
            'idempotency_key' => $idempotencyKey,
            'consumed_step_index' => null,
            'consumed_at' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if ($idempotencyKey !== null) {
            $inserted = $this->signalTable()->insertOrIgnore($row);

            /** @var object|null $record */
            $record = $this->signalTable()
                ->where('run_id', $runId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($record === null) {
                throw new SwarmException("Unable to record durable signal [{$name}] for run [{$runId}].");
            }

            return array_merge($this->mapSignal($record), ['duplicate' => $inserted === 0]);
        }

        $id = $this->signalTable()->insertGetId($row);

        /** @var object $record */
        $record = $this->signalTable()->where('id', $id)->first();

        return array_merge($this->mapSignal($record), ['duplicate' => false]);
    }

    public function signals(string $runId): array
    {
        return $this->signalTable()
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $record): array => $this->mapSignal($record))
            ->all();
    }

    public function createWait(string $runId, string $name, ?string $reason = null, ?int $timeoutSeconds = null, array $metadata = []): void
    {
        $timestamp = Carbon::now('UTC');
        $timeoutAt = $timeoutSeconds !== null ? $timestamp->copy()->addSeconds($timeoutSeconds) : null;

        $this->connection->transaction(function () use ($runId, $name, $reason, $timeoutAt, $metadata, $timestamp): void {
            $this->waitTable()->insert([
                'run_id' => $runId,
                'name' => $name,
                'status' => 'waiting',
                'reason' => $reason,
                'timeout_at' => $timeoutAt,
                'signal_id' => null,
                'outcome' => null,
                'metadata' => $this->encodeJson($metadata),
                'finished_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $this->syncRunWaitSummary($runId, $timestamp);
        });
    }

    public function releaseWaitWithSignal(string $runId, string $name, int $signalId): bool
    {
        $timestamp = Carbon::now('UTC');

        return $this->connection->transaction(function () use ($runId, $name, $signalId, $timestamp): bool {
            $updated = $this->waitTable()
                ->where('run_id', $runId)
                ->where('name', $name)
                ->where('status', 'waiting')
                ->update([
                    'status' => 'signalled',
                    'signal_id' => $signalId,
                    'outcome' => $this->encodeJson(['status' => 'signalled', 'timed_out' => false]),
                    'finished_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

            if ($updated !== 1) {
                return false;
            }

            $this->signalTable()->where('id', $signalId)->update([
                'status' => 'consumed',
                'consumed_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $this->syncRunWaitSummary($runId, $timestamp);

            return true;
        });
    }

    public function waits(string $runId): array
    {
        return $this->waitTable()
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $record): array => $this->mapWait($record))
            ->all();
    }

    public function releaseWaitWithOutcome(string $runId, string $name, string $status, array $outcome): bool
    {
        $timestamp = Carbon::now('UTC');

        return $this->connection->transaction(function () use ($runId, $name, $status, $outcome, $timestamp): bool {
            $updated = $this->waitTable()
                ->where('run_id', $runId)
                ->where('name', $name)
                ->where('status', 'waiting')
                ->update([
                    'status' => $status,
                    'outcome' => $this->encodeJson($outcome),
                    'finished_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

            if ($updated !== 1) {
                return false;
            }

            $this->syncRunWaitSummary($runId, $timestamp);

            return true;
        });
    }

    public function recoverableWaitTimeouts(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        $query = $this->table()
            ->where('status', 'waiting')
            ->whereNull('finished_at')
            ->whereNotNull('wait_timeout_at')
            ->where('wait_timeout_at', '<=', Carbon::now('UTC'))
            ->orderBy('wait_timeout_at')
            ->limit($limit);

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        if ($swarmClass !== null) {
            $query->where('swarm_class', $swarmClass);
        }

        return $query->get()->map(fn (object $record): ?array => $this->find($record->run_id))->filter()->values()->all();
    }

    public function recoverableTimedOutWaits(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        $now = Carbon::now('UTC');
        $waitTable = (string) $this->config->get('swarm.tables.durable_waits', 'swarm_durable_waits');
        $durableTable = (string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs');

        $query = $this->connection->table($waitTable.' as waits')
            ->join($durableTable.' as runs', 'waits.run_id', '=', 'runs.run_id')
            ->where('waits.status', 'waiting')
            ->whereNotNull('waits.timeout_at')
            ->where('waits.timeout_at', '<=', $now)
            ->where('runs.status', 'waiting')
            ->whereNull('runs.finished_at')
            ->orderBy('waits.timeout_at')
            ->limit($limit)
            ->select([
                'waits.run_id',
                'waits.name as wait_name',
                'waits.timeout_at',
                'runs.swarm_class',
                'runs.topology',
                'runs.execution_mode',
                'runs.coordination_profile',
                'runs.next_step_index',
                'runs.queue_connection',
                'runs.queue_name',
            ]);

        if ($runId !== null) {
            $query->where('waits.run_id', $runId);
        }

        if ($swarmClass !== null) {
            $query->where('runs.swarm_class', $swarmClass);
        }

        return $query->get()
            ->map(fn (object $record): array => [
                'run_id' => $record->run_id,
                'wait_name' => $record->wait_name,
                'timeout_at' => $record->timeout_at,
                'swarm_class' => $record->swarm_class,
                'topology' => $record->topology,
                'execution_mode' => $record->execution_mode ?? 'durable',
                'coordination_profile' => $record->coordination_profile ?? CoordinationProfile::StepDurable->value,
                'next_step_index' => (int) $record->next_step_index,
                'queue_connection' => $record->queue_connection,
                'queue_name' => $record->queue_name,
            ])
            ->all();
    }

    public function releaseTimedOutWait(string $runId, string $name): bool
    {
        $timestamp = Carbon::now('UTC');

        return $this->connection->transaction(function () use ($runId, $name, $timestamp): bool {
            $updated = $this->waitTable()
                ->where('run_id', $runId)
                ->where('name', $name)
                ->where('status', 'waiting')
                ->where('timeout_at', '<=', $timestamp)
                ->update([
                    'status' => 'timed_out',
                    'outcome' => $this->encodeJson(['status' => 'timed_out', 'timed_out' => true]),
                    'finished_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

            if ($updated !== 1) {
                return false;
            }

            $this->syncRunWaitSummary($runId, $timestamp);

            return true;
        });
    }

    public function recordProgress(string $runId, ?string $branchId, array $progress): void
    {
        $timestamp = Carbon::now('UTC');
        $branch = $branchId !== null ? $this->findBranch($runId, $branchId) : null;
        $run = $this->find($runId);
        $storedBranchId = $branchId ?? '';

        $this->progressTable()->upsert([
            [
                'run_id' => $runId,
                'branch_id' => $storedBranchId,
                'step_index' => $branch['step_index'] ?? $run['current_step_index'] ?? null,
                'agent_class' => $branch['agent_class'] ?? null,
                'progress' => $this->encodeJson($progress),
                'last_progress_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ], ['run_id', 'branch_id'], ['step_index', 'agent_class', 'progress', 'last_progress_at', 'updated_at']);

        if ($branchId !== null) {
            $this->branchTable()->where('run_id', $runId)->where('branch_id', $branchId)->update([
                'last_progress_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return;
        }

        $this->table()->where('run_id', $runId)->update([
            'last_progress_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function progress(string $runId): array
    {
        return $this->progressTable()
            ->where('run_id', $runId)
            ->orderBy('branch_id')
            ->get()
            ->map(fn (object $record): array => $this->mapProgress($record))
            ->all();
    }

    public function createChildRun(string $parentRunId, string $childRunId, string $childSwarmClass, string $waitName, array $contextPayload): void
    {
        $timestamp = Carbon::now('UTC');

        $this->childRunTable()->upsert([
            [
                'parent_run_id' => $parentRunId,
                'child_run_id' => $childRunId,
                'child_swarm_class' => $childSwarmClass,
                'wait_name' => $waitName,
                'context_payload' => $this->encodeJson($contextPayload),
                'status' => 'pending',
                'output' => null,
                'failure' => null,
                'dispatched_at' => null,
                'terminal_event_dispatched_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ], ['child_run_id'], ['child_swarm_class', 'wait_name', 'context_payload', 'status', 'updated_at']);
    }

    public function childRunForChild(string $childRunId): ?array
    {
        /** @var object|null $record */
        $record = $this->childRunTable()->where('child_run_id', $childRunId)->first();

        return $record !== null ? $this->mapChildRun($record) : null;
    }

    public function markChildRunDispatched(string $childRunId): void
    {
        $timestamp = Carbon::now('UTC');

        $this->childRunTable()
            ->where('child_run_id', $childRunId)
            ->whereNull('dispatched_at')
            ->update([
                'dispatched_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
    }

    public function updateChildRun(string $childRunId, string $status, ?string $output = null, ?array $failure = null): void
    {
        $this->childRunTable()->where('child_run_id', $childRunId)->update([
            'status' => $status,
            'output' => $output,
            'failure' => $failure !== null ? $this->encodeJson($failure) : null,
            'updated_at' => Carbon::now('UTC'),
        ]);
    }

    public function markChildTerminalEventDispatched(string $childRunId): bool
    {
        $timestamp = Carbon::now('UTC');
        $updated = $this->childRunTable()
            ->where('child_run_id', $childRunId)
            ->whereNull('terminal_event_dispatched_at')
            ->update([
                'terminal_event_dispatched_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

        return $updated === 1;
    }

    public function undispatchedChildRuns(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        $childTable = (string) $this->config->get('swarm.tables.durable_child_runs', 'swarm_durable_child_runs');
        $durableTable = (string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs');

        $query = $this->connection->table($childTable.' as children')
            ->join($durableTable.' as parents', 'children.parent_run_id', '=', 'parents.run_id')
            ->whereIn('children.status', ['pending', 'running'])
            ->whereNull('children.dispatched_at')
            ->whereNull('parents.finished_at')
            ->orderBy('children.created_at')
            ->limit($limit)
            ->select('children.*');

        if ($runId !== null) {
            $query->where('children.parent_run_id', $runId);
        }

        if ($swarmClass !== null) {
            $query->where('parents.swarm_class', $swarmClass);
        }

        return $query->get()
            ->map(fn (object $record): array => $this->mapChildRun($record))
            ->all();
    }

    public function parentsWaitingOnTerminalChildren(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        $childTable = (string) $this->config->get('swarm.tables.durable_child_runs', 'swarm_durable_child_runs');
        $durableTable = (string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs');

        $query = $this->connection->table($childTable.' as children')
            ->join($durableTable.' as parents', 'children.parent_run_id', '=', 'parents.run_id')
            ->where('parents.status', 'waiting')
            ->whereIn('children.status', ['completed', 'failed', 'cancelled'])
            ->whereNull('parents.finished_at')
            ->orderBy('children.updated_at')
            ->limit($limit)
            ->select('parents.*');

        if ($runId !== null) {
            $query->where('parents.run_id', $runId);
        }

        if ($swarmClass !== null) {
            $query->where('parents.swarm_class', $swarmClass);
        }

        return $query->get()->map(fn (object $record): ?array => $this->find($record->run_id))->filter()->values()->all();
    }

    public function childRuns(string $runId): array
    {
        return $this->childRunTable()
            ->where('parent_run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $record): array => $this->mapChildRun($record))
            ->all();
    }

    public function reserveWebhookIdempotency(string $scope, string $idempotencyKey, string $requestHash): array
    {
        $timestamp = Carbon::now('UTC');

        try {
            $this->webhookIdempotencyTable()->insert([
                'scope' => $scope,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => 'reserved',
                'run_id' => null,
                'response_payload' => null,
                'completed_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return [
                'reserved' => true,
                'duplicate' => false,
                'conflict' => false,
                'in_flight' => false,
                'record' => null,
            ];
        } catch (\Throwable) {
            /** @var object|null $record */
            $record = $this->webhookIdempotencyTable()
                ->where('scope', $scope)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($record === null) {
                throw new SwarmException('Unable to reserve swarm webhook idempotency key.');
            }

            $mapped = $this->mapWebhookIdempotency($record);
            $conflict = $mapped['request_hash'] !== $requestHash;
            $duplicate = ! $conflict && $mapped['status'] === 'completed';

            if ($conflict) {
                return [
                    'reserved' => false,
                    'duplicate' => false,
                    'conflict' => true,
                    'in_flight' => false,
                    'record' => $mapped,
                ];
            }

            if ($duplicate) {
                return [
                    'reserved' => false,
                    'duplicate' => true,
                    'conflict' => false,
                    'in_flight' => false,
                    'record' => $mapped,
                ];
            }

            if ($mapped['status'] === 'failed') {
                $updated = $this->webhookIdempotencyTable()
                    ->where('scope', $scope)
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('request_hash', $requestHash)
                    ->where('status', 'failed')
                    ->update([
                        'status' => 'reserved',
                        'run_id' => null,
                        'response_payload' => null,
                        'completed_at' => null,
                        'updated_at' => $timestamp,
                    ]);

                if ($updated === 1) {
                    return [
                        'reserved' => true,
                        'duplicate' => false,
                        'conflict' => false,
                        'in_flight' => false,
                        'record' => null,
                    ];
                }

                /** @var object|null $record */
                $record = $this->webhookIdempotencyTable()
                    ->where('scope', $scope)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($record === null) {
                    throw new SwarmException('Unable to reserve swarm webhook idempotency key.');
                }

                $mapped = $this->mapWebhookIdempotency($record);
            }

            return [
                'reserved' => false,
                'duplicate' => false,
                'conflict' => false,
                'in_flight' => true,
                'record' => $mapped,
            ];
        }
    }

    public function completeWebhookIdempotency(string $scope, string $idempotencyKey, string $runId, array $responsePayload): void
    {
        $timestamp = Carbon::now('UTC');

        $this->webhookIdempotencyTable()
            ->where('scope', $scope)
            ->where('idempotency_key', $idempotencyKey)
            ->update([
                'status' => 'completed',
                'run_id' => $runId,
                'response_payload' => $this->encodeJson($responsePayload),
                'completed_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
    }

    public function failWebhookIdempotency(string $scope, string $idempotencyKey): void
    {
        $this->webhookIdempotencyTable()
            ->where('scope', $scope)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'reserved')
            ->whereNull('run_id')
            ->update([
                'status' => 'failed',
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

    protected function syncRunWaitSummary(string $runId, Carbon $timestamp): void
    {
        /** @var object|null $latestOpenWait */
        $latestOpenWait = $this->waitTable()
            ->where('run_id', $runId)
            ->where('status', 'waiting')
            ->orderByDesc('id')
            ->first();

        if ($latestOpenWait === null) {
            $this->table()
                ->where('run_id', $runId)
                ->where('status', 'waiting')
                ->whereNull('finished_at')
                ->update([
                    'status' => 'pending',
                    'wait_reason' => null,
                    'waiting_since' => null,
                    'wait_timeout_at' => null,
                    'updated_at' => $timestamp,
                ]);

            return;
        }

        /** @var object|null $nextTimedWait */
        $nextTimedWait = $this->waitTable()
            ->where('run_id', $runId)
            ->where('status', 'waiting')
            ->whereNotNull('timeout_at')
            ->orderBy('timeout_at')
            ->first();

        $this->table()
            ->where('run_id', $runId)
            ->whereNull('finished_at')
            ->update([
                'status' => 'waiting',
                'wait_reason' => $latestOpenWait->reason ?? null,
                'waiting_since' => $latestOpenWait->created_at,
                'wait_timeout_at' => $nextTimedWait->timeout_at ?? null,
                'updated_at' => $timestamp,
            ]);
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
     * @param  array<string, mixed>  $run
     */
    protected function branchesAreTerminalForWaitingRun(array $run): bool
    {
        $parentNodeId = $run['current_node_id'] ?? null;

        if (! is_string($parentNodeId)) {
            return false;
        }

        $branches = $this->branchesFor((string) $run['run_id'], $parentNodeId);

        if ($branches === []) {
            return false;
        }

        foreach ($branches as $branch) {
            if (! in_array($branch['status'] ?? null, ['completed', 'failed', 'cancelled'], true)) {
                return false;
            }
        }

        return true;
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
            'last_progress_at' => $record->last_progress_at ?? null,
            'retry_policy' => $this->decodeJson($record->retry_policy ?? null, null),
            'retry_attempt' => (int) ($record->retry_attempt ?? 0),
            'next_retry_at' => $record->next_retry_at ?? null,
            'expires_at' => $record->expires_at ?? null,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array<string, bool|int|float|string|null>
     */
    protected function normalizeLabels(array $labels): array
    {
        $normalized = [];

        foreach ($labels as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new SwarmException('Durable run labels must use non-empty string keys.');
            }

            if (! is_bool($value) && ! is_int($value) && ! is_float($value) && ! is_string($value) && $value !== null) {
                throw new SwarmException("Durable run label [{$key}] must be a scalar or null.");
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapSignal(object $record): array
    {
        return [
            'id' => (int) $record->id,
            'run_id' => $record->run_id,
            'name' => $record->name,
            'status' => $record->status,
            'payload' => $this->decodeJson($record->payload ?? null, null),
            'idempotency_key' => $record->idempotency_key,
            'consumed_step_index' => $record->consumed_step_index !== null ? (int) $record->consumed_step_index : null,
            'consumed_at' => $record->consumed_at ?? null,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapWait(object $record): array
    {
        return [
            'id' => (int) $record->id,
            'run_id' => $record->run_id,
            'name' => $record->name,
            'status' => $record->status,
            'reason' => $record->reason,
            'timeout_at' => $record->timeout_at ?? null,
            'signal_id' => $record->signal_id !== null ? (int) $record->signal_id : null,
            'outcome' => $this->decodeJson($record->outcome ?? null, null),
            'metadata' => $this->decodeJson($record->metadata ?? null, []),
            'finished_at' => $record->finished_at ?? null,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapProgress(object $record): array
    {
        return [
            'run_id' => $record->run_id,
            'branch_id' => $record->branch_id !== '' ? $record->branch_id : null,
            'step_index' => $record->step_index !== null ? (int) $record->step_index : null,
            'agent_class' => $record->agent_class,
            'progress' => $this->decodeJson($record->progress ?? null, []),
            'last_progress_at' => $record->last_progress_at,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapChildRun(object $record): array
    {
        return [
            'parent_run_id' => $record->parent_run_id,
            'child_run_id' => $record->child_run_id,
            'child_swarm_class' => $record->child_swarm_class,
            'wait_name' => $record->wait_name,
            'context_payload' => $this->decodeJson($record->context_payload ?? null, []),
            'status' => $record->status,
            'output' => $record->output,
            'failure' => $this->decodeJson($record->failure ?? null, null),
            'dispatched_at' => $record->dispatched_at ?? null,
            'terminal_event_dispatched_at' => $record->terminal_event_dispatched_at ?? null,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapWebhookIdempotency(object $record): array
    {
        return [
            'scope' => $record->scope,
            'idempotency_key' => $record->idempotency_key,
            'request_hash' => $record->request_hash,
            'status' => $record->status,
            'run_id' => $record->run_id ?? null,
            'response_payload' => $this->decodeJson($record->response_payload ?? null, null),
            'completed_at' => $record->completed_at ?? null,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    protected function signalTable()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.durable_signals', 'swarm_durable_signals'));
    }

    protected function waitTable()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.durable_waits', 'swarm_durable_waits'));
    }

    protected function labelTable()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.durable_labels', 'swarm_durable_labels'));
    }

    protected function detailTable()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.durable_details', 'swarm_durable_details'));
    }

    protected function progressTable()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.durable_progress', 'swarm_durable_progress'));
    }

    protected function childRunTable()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.durable_child_runs', 'swarm_durable_child_runs'));
    }

    protected function webhookIdempotencyTable()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.durable_webhook_idempotency', 'swarm_durable_webhook_idempotency'));
    }
}
