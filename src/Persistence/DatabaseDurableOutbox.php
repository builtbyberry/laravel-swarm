<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Enums\OutboxDispatchType;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Throwable;
use UnexpectedValueException;

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
    public function drain(array $types = [], int $limit = 100): DrainResult
    {
        if ($limit < 1) {
            return new DrainResult(0, 0, 0, 0, 0);
        }

        $reservationTimeoutSeconds = (int) $this->config->get('swarm.durable.relay.reservation_timeout_seconds', 60);
        $now = Carbon::now('UTC');
        $staleThreshold = $now->copy()->subSeconds($reservationTimeoutSeconds);

        $typeValues = array_map(static fn (OutboxDispatchType $t): string => $t->value, $types);

        // Validate the stored queue connection against the application's known connections
        // so a tampered or stale DB row cannot reroute jobs to an unexpected driver.
        // Resolved once per drain() call rather than per-entry to avoid repeated config reads.
        $knownConnections = array_keys((array) $this->config->get('queue.connections', []));

        // Phase 1: claim rows atomically inside a short transaction.
        // Dispatch happens outside (queue systems are not DB transaction participants —
        // dispatching inside a transaction risks duplicate jobs if the commit fails after
        // a successful queue push).
        $entries = $this->connection->transaction(function () use ($now, $staleThreshold, $typeValues, $limit) {
            $query = $this->table()
                ->where(function ($q) use ($staleThreshold): void {
                    $q->whereNull('reserved_at')
                        ->orWhere('reserved_at', '<', $staleThreshold);
                })
                ->where('available_at', '<=', $now)
                ->orderBy('id')
                ->limit($limit);

            if ($typeValues !== []) {
                $query->whereIn('dispatch_type', $typeValues);
            }

            // Use FOR UPDATE SKIP LOCKED so concurrent relay workers each claim
            // a distinct batch rather than blocking on each other's locks.
            // SQLite uses file-level locking via its transaction; FOR UPDATE SKIP LOCKED
            // is not supported and would produce a no-op at best. Both clauses are skipped
            // on SQLite so tests run cleanly without any locking divergence.
            if ($this->connection->getDriverName() !== 'sqlite') {
                $query->lock('for update skip locked');
            }

            $entries = $query->get();

            if ($entries->isEmpty()) {
                return $entries;
            }

            $this->table()->whereIn('id', $entries->pluck('id')->all())->update(['reserved_at' => $now]);

            return $entries;
        });

        if ($entries->isEmpty()) {
            return new DrainResult(0, 0, 0, 0, 0);
        }

        $claimed = $entries->count();
        $reclaimed = $entries->filter(fn (object $e): bool => $e->reserved_at !== null)->count();

        // Phase 2: dispatch each entry individually, outside any transaction.
        //
        // Three outcomes per entry:
        //   a) Success       — dispatched to queue, ID collected for batched delete, $dispatched++
        //   b) Invalid entry — permanently corrupt (unknown type, unknown queue_connection,
        //                      or malformed/missing payload fields); deleted immediately,
        //                      reported to the error handler, $skipped++
        //   c) Transient err — queue driver unavailable etc.; entry retains reserved_at and
        //                      becomes re-claimable after the reservation timeout expires, $failed++
        //
        // Collecting successful IDs and deleting in a single WHERE IN at the end is more
        // efficient than N individual DELETEs, particularly when limit is large.
        $dispatched = 0;
        $skipped = 0;
        $failed = 0;
        $dispatchedIds = [];

        foreach ($entries as $entry) {
            try {
                $this->dispatchEntry($entry, $knownConnections);
                $dispatchedIds[] = $entry->id;
                $dispatched++;
            } catch (UnexpectedValueException $e) {
                // Permanently invalid entry — cannot succeed on retry. Delete it to
                // prevent it from clogging the outbox indefinitely, and route it through
                // the application error handler so it appears in Sentry / logs.
                report($e);
                $this->table()->where('id', $entry->id)->delete();
                $skipped++;
            } catch (Throwable $e) {
                // Transient failure: preserve reserved_at so the entry is re-claimable.
                // Report so the outage surfaces in the error tracker.
                report($e);
                $failed++;
            }
        }

        if ($dispatchedIds !== []) {
            $this->table()->whereIn('id', $dispatchedIds)->delete();
        }

        return new DrainResult($dispatched, $skipped, $failed, $claimed, $reclaimed);
    }

    /**
     * @param  list<string>  $knownConnections
     *
     * @throws UnexpectedValueException For permanently invalid entries: unknown dispatch_type,
     *                                  unknown queue_connection, or malformed/missing payload fields.
     */
    protected function dispatchEntry(object $entry, array $knownConnections): void
    {
        $type = OutboxDispatchType::tryFrom($entry->dispatch_type);

        if ($type === null) {
            throw new UnexpectedValueException(
                "Unknown outbox dispatch_type [{$entry->dispatch_type}] for entry [{$entry->id}]."
            );
        }

        try {
            $payload = is_string($entry->payload)
                ? json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR)
                : (array) $entry->payload;
        } catch (\JsonException) {
            throw new UnexpectedValueException(
                "Invalid JSON payload for outbox entry [{$entry->id}] (type: {$entry->dispatch_type})."
            );
        }

        if (! is_array($payload)) {
            // is_array() catches valid-but-non-object JSON (e.g. the literal "null") that
            // json_decode succeeds on but cannot be used as an associative payload array.
            throw new UnexpectedValueException(
                "Invalid JSON payload for outbox entry [{$entry->id}] (type: {$entry->dispatch_type})."
            );
        }

        // An unknown queue_connection is a permanently invalid row — the connection name
        // will not appear in config without a deployment, so retrying indefinitely is useless
        // and silently falling back to the default queue would break queue-isolation guarantees.
        if ($entry->queue_connection !== null && ! in_array($entry->queue_connection, $knownConnections, true)) {
            throw new UnexpectedValueException(
                "Unknown queue_connection [{$entry->queue_connection}] for outbox entry [{$entry->id}]. "
                .'Verify your queue.connections config matches what was stored when the run was dispatched.'
            );
        }

        $connection = $entry->queue_connection ?: null;

        // An empty queue_name string is treated as null (use the driver's default queue).
        // Writers that have no queue preference store null or '' — the ?: operator normalises both.
        $queue = $entry->queue_name ?: null;

        match ($type) {
            OutboxDispatchType::Step => $this->jobs->dispatchStep(
                $entry->run_id,
                $this->extractStepIndex($entry->id, $payload),
                $connection,
                $queue,
            ),
            OutboxDispatchType::Branch => $this->jobs->dispatchBranch(
                $entry->run_id,
                $this->extractBranchId($entry->id, $payload),
                $connection,
                $queue,
            ),
            OutboxDispatchType::QueuedResume => $this->jobs->dispatchQueuedResumeById(
                $entry->run_id,
                $connection,
                $queue,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws UnexpectedValueException
     */
    protected function extractStepIndex(int|string $entryId, array $payload): int
    {
        if (! array_key_exists('step_index', $payload)) {
            throw new UnexpectedValueException(
                "Missing required payload field [step_index] for step outbox entry [{$entryId}]."
            );
        }

        $value = $payload['step_index'];

        if (! is_int($value)) {
            throw new UnexpectedValueException(
                "Invalid payload field [step_index] for step outbox entry [{$entryId}]: expected int, got "
                .get_debug_type($value).'.'
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws UnexpectedValueException
     */
    protected function extractBranchId(int|string $entryId, array $payload): string
    {
        if (! array_key_exists('branch_id', $payload)) {
            throw new UnexpectedValueException(
                "Missing required payload field [branch_id] for branch outbox entry [{$entryId}]."
            );
        }

        $value = $payload['branch_id'];

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException(
                "Invalid payload field [branch_id] for branch outbox entry [{$entryId}]: expected non-empty string, got "
                .(is_string($value) ? 'empty string' : get_debug_type($value)).'.'
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
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

    protected function table(): Builder
    {
        return $this->connection->table(
            (string) $this->config->get('swarm.tables.durable_outbox', 'swarm_durable_outbox'),
        );
    }
}
