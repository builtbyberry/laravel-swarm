<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Enums\OutboxDispatchType;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

class DatabaseDurableOutbox implements DurableOutbox
{
    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected DurableJobDispatcher $jobs,
    ) {}

    public function enqueueStep(string $runId, int $stepIndex, ?string $connection, ?string $queue): void
    {
        $this->insert(OutboxDispatchType::Step, $runId, ['step_index' => $stepIndex], $connection, $queue);
    }

    public function enqueueBranch(string $runId, string $branchId, ?string $connection, ?string $queue): void
    {
        $this->insert(OutboxDispatchType::Branch, $runId, ['branch_id' => $branchId], $connection, $queue);
    }

    public function enqueueQueuedResume(string $runId, ?string $connection, ?string $queue): void
    {
        $this->insert(OutboxDispatchType::QueuedResume, $runId, [], $connection, $queue);
    }

    /**
     * @param  array<OutboxDispatchType>  $types
     */
    public function drain(array $types = [], int $limit = 100): int
    {
        $reservationTimeoutSeconds = (int) $this->config->get('swarm.durable.relay.reservation_timeout_seconds', 60);
        $now = Carbon::now('UTC');
        $staleThreshold = $now->copy()->subSeconds($reservationTimeoutSeconds);

        $typeValues = array_map(static fn (OutboxDispatchType $t): string => $t->value, $types);

        $dispatched = 0;

        $this->connection->transaction(function () use ($now, $staleThreshold, $typeValues, $limit, &$dispatched): void {
            $query = $this->table()
                ->where(function ($q) use ($now, $staleThreshold): void {
                    $q->whereNull('reserved_at')
                        ->orWhere('reserved_at', '<', $staleThreshold);
                })
                ->where('available_at', '<=', $now)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate();

            if ($typeValues !== []) {
                $query->whereIn('dispatch_type', $typeValues);
            }

            $entries = $query->get();

            if ($entries->isEmpty()) {
                return;
            }

            $ids = $entries->pluck('id')->all();

            $this->table()->whereIn('id', $ids)->update(['reserved_at' => $now]);

            foreach ($entries as $entry) {
                $this->dispatchEntry($entry);
                $this->table()->where('id', $entry->id)->delete();
                $dispatched++;
            }
        });

        return $dispatched;
    }

    protected function dispatchEntry(object $entry): void
    {
        $type = OutboxDispatchType::from($entry->dispatch_type);
        $payload = is_string($entry->payload) ? json_decode($entry->payload, true) : (array) $entry->payload;

        match ($type) {
            OutboxDispatchType::Step => $this->jobs->dispatchStep(
                $entry->run_id,
                (int) $payload['step_index'],
                $entry->queue_connection ?: null,
                $entry->queue_name ?: null,
            ),
            OutboxDispatchType::Branch => $this->jobs->dispatchBranch(
                $entry->run_id,
                (string) $payload['branch_id'],
                $entry->queue_connection ?: null,
                $entry->queue_name ?: null,
            ),
            OutboxDispatchType::QueuedResume => $this->jobs->dispatchQueuedResumeById(
                $entry->run_id,
                $entry->queue_connection ?: null,
                $entry->queue_name ?: null,
            ),
        };
    }

    protected function insert(OutboxDispatchType $type, string $runId, array $payload, ?string $connection, ?string $queue): void
    {
        $now = Carbon::now('UTC');

        $this->table()->insert([
            'run_id' => $runId,
            'dispatch_type' => $type->value,
            'payload' => json_encode($payload),
            'queue_connection' => $connection,
            'queue_name' => $queue,
            'available_at' => $now,
            'reserved_at' => null,
            'created_at' => $now,
        ]);
    }

    protected function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(
            (string) $this->config->get('swarm.tables.durable_outbox', 'swarm_durable_outbox'),
        );
    }
}
