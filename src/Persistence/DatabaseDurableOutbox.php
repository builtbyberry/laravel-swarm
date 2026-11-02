<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Enums\DurableDispatchType;
use BuiltByBerry\LaravelSwarm\Enums\RelayLane;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * @internal
 */
class DatabaseDurableOutbox implements DurableOutbox
{
    use SafeReporting;

    /**
     * The available_at value that marks a branch row as HELD by the fan-out cap
     * (swarm.durable.parallel.max_concurrent_branches). It sits just inside the
     * range of a MySQL `timestamp` column (which ends 2038-01-19 03:14:07 UTC),
     * with weeks of margin so a non-UTC session time zone cannot push it out of
     * range. It is reserved: no ordinary write stores it (see dueAt()), so a held
     * row is identified by equality, never by comparing it with the clock — it can
     * never come due by itself.
     */
    public const HOLD_UNTIL = '2038-01-01 00:00:00';

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected DurableJobDispatcher $jobs,
    ) {}

    public function enqueueStep(string $runId, int $stepIndex, ?string $connection, ?string $queue, bool $directDispatch = false): int
    {
        return $this->insert(DurableDispatchType::Step, $runId, ['step_index' => $stepIndex], $connection, $queue, $directDispatch);
    }

    public function enqueueBranch(string $runId, string $branchId, ?string $connection, ?string $queue, bool $directDispatch = false): int
    {
        return $this->insert(DurableDispatchType::Branch, $runId, ['branch_id' => $branchId], $connection, $queue, $directDispatch);
    }

    public function enqueueQueuedResume(string $runId, ?string $connection, ?string $queue): void
    {
        $this->insert(DurableDispatchType::QueuedResume, $runId, [], $connection, $queue, false);
    }

    public function settle(int $entryId, ?string $runId = null): void
    {
        try {
            $this->table()->where('id', $entryId)->delete();
        } catch (Throwable $e) {
            // A failed settle leaves the row reserved; the relay reclaims it once the
            // reservation goes stale and dispatches a duplicate that acquireLease()
            // turns into a no-op. Never fail the step for it.
            $this->safeReport(new RuntimeException(
                "Failed to settle durable outbox entry [{$entryId}]".($runId !== null ? " for run [{$runId}]" : '').'; swarm:relay will dispatch it again as a no-op once its reservation goes stale.',
                previous: $e,
            ));
        }
    }

    /**
     * @param  array<DurableDispatchType>  $types
     */
    public function drain(array $types = [], int $limit = 100): DrainResult
    {
        if ($limit < 1) {
            return new DrainResult(0, 0, 0, 0, 0);
        }

        $reservationTimeoutSeconds = (int) $this->config->get('swarm.durable.relay.reservation_timeout_seconds', 60);
        $now = Carbon::now('UTC');
        $staleThreshold = $now->copy()->subSeconds($reservationTimeoutSeconds);

        $typeValues = array_map(static fn (DurableDispatchType $t): string => $t->value, $types);

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
                ->where(fn (Builder $q) => $this->whereNotHeld($q))
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
        // A direct-dispatch row carries "direct" in its payload and is written with
        // reserved_at = available_at (= created_at, or the release time for a branch the
        // fan-out cap held); a relay claim overwrites reserved_at. Both together mark a
        // direct row reclaimed for the first time. (Timestamps alone are not enough:
        // they are second-precision, so a relay row claimed in its due second would match.)
        $reclaimedDirect = $entries->filter(
            fn (object $e): bool => $e->reserved_at !== null
                && (string) $e->reserved_at === (string) $e->available_at
                && $this->writtenForDirectDispatch($e),
        )->count();

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

        // One dispatcher copy per drain: entries of the same run share a single read of
        // its native-input version, and the next drain reads it fresh.
        $jobs = $this->jobs->forDrain();

        foreach ($entries as $entry) {
            try {
                $this->dispatchEntry($entry, $knownConnections, $jobs);
                $dispatchedIds[] = $entry->id;
                $dispatched++;
            } catch (UnexpectedValueException $e) {
                // Permanently invalid entry — cannot succeed on retry. Delete it to
                // prevent it from clogging the outbox indefinitely, and route it through
                // the application error handler so it appears in Sentry / logs.
                $this->safeReport($e);
                $this->table()->where('id', $entry->id)->delete();
                $skipped++;
            } catch (Throwable $e) {
                // Transient failure: preserve reserved_at so the entry is re-claimable.
                // Report so the outage surfaces in the error tracker.
                $this->safeReport($e);
                $failed++;
            }
        }

        if ($dispatchedIds !== []) {
            $this->table()->whereIn('id', $dispatchedIds)->delete();
        }

        return new DrainResult($dispatched, $skipped, $failed, $claimed, $reclaimed, $reclaimedDirect);
    }

    /**
     * @param  list<string>  $knownConnections
     * @param  DurableJobDispatcher  $jobs  The dispatcher this drain builds its jobs with; see DurableJobDispatcher::forDrain().
     *
     * @throws UnexpectedValueException For permanently invalid entries: unknown dispatch_type,
     *                                  unknown queue_connection, or malformed/missing payload fields.
     */
    protected function dispatchEntry(object $entry, array $knownConnections, DurableJobDispatcher $jobs): void
    {
        // A misrouted audit row is a distinct, diagnosable failure: audit evidence
        // belongs in swarm_audit_outbox and is drained by AuditOutbox, never here.
        // Name it explicitly rather than letting it fall through to the generic
        // "unknown dispatch_type" message below.
        if ($entry->dispatch_type === RelayLane::Audit->value) {
            throw new UnexpectedValueException(
                "Audit dispatch type [{$entry->dispatch_type}] for entry [{$entry->id}] was routed to the durable outbox; "
                .'audit records belong in the swarm_audit_outbox table and are drained by AuditOutbox.'
            );
        }

        $type = DurableDispatchType::tryFrom($entry->dispatch_type);

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
            DurableDispatchType::Step => $jobs->dispatchStep(
                $entry->run_id,
                $this->extractStepIndex($entry->id, $payload),
                $connection,
                $queue,
            ),
            DurableDispatchType::Branch => $jobs->dispatchBranch(
                $entry->run_id,
                $this->extractBranchId($entry->id, $payload),
                $connection,
                $queue,
            ),
            DurableDispatchType::QueuedResume => $jobs->dispatchQueuedResumeById(
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
     * A direct-dispatch row is born reserved: drain()'s claim predicate skips it
     * until the reservation is older than reservation_timeout_seconds, which is
     * exactly the existing stale-reclaim path a relay-claimed row takes when its
     * relay dies mid-run.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function insert(DurableDispatchType $type, string $runId, array $payload, ?string $connection, ?string $queue, bool $directDispatch): int
    {
        $now = $this->dueAt(Carbon::now('UTC'));

        return (int) $this->table()->insertGetId([
            'run_id' => $runId,
            'dispatch_type' => $type->value,
            'payload' => json_encode($directDispatch ? [...$payload, 'direct' => true] : $payload),
            'queue_connection' => $connection,
            'queue_name' => $queue,
            'available_at' => $now,
            'reserved_at' => $directDispatch ? $now : null,
            'created_at' => $now,
        ]);
    }

    /**
     * Write a HELD branch row: it is neither due nor reserved, so the relay never
     * claims it, and only releaseHeldBranches() makes it dispatchable.
     */
    public function holdBranch(string $runId, string $branchId, ?string $connection, ?string $queue): int
    {
        $now = $this->dueAt(Carbon::now('UTC'));

        return (int) $this->table()->insertGetId([
            'run_id' => $runId,
            'dispatch_type' => DurableDispatchType::Branch->value,
            'payload' => json_encode(['branch_id' => $branchId, 'held' => true]),
            'queue_connection' => $connection,
            'queue_name' => $queue,
            'available_at' => self::HOLD_UNTIL,
            'reserved_at' => null,
            'created_at' => $now,
        ]);
    }

    /**
     * The run's held rows, oldest first, as entry id => branch id.
     *
     * $lock reads them with a shared lock: a current read of committed state even
     * inside a transaction holding an older snapshot (MySQL REPEATABLE READ), used
     * by the execution-time admission check.
     *
     * @return array<int, string>
     */
    public function heldBranches(string $runId, bool $lock = false): array
    {
        $query = $this->heldQuery()->where('run_id', $runId)->orderBy('id');

        if ($lock && $this->connection->getDriverName() !== 'sqlite') {
            $query->sharedLock();
        }

        $held = [];

        foreach ($query->get(['id', 'payload']) as $row) {
            $branchId = $this->payloadBranchId($row);

            if ($branchId !== null) {
                $held[(int) $row->id] = $branchId;
            }
        }

        return $held;
    }

    /**
     * Release the given held rows (entry ids from heldBranches()) so they dispatch.
     * Call inside the transaction that holds the run's admission lock. Each row's
     * routing is decided from its own queue connection: $directFor(connection)
     * true writes it born reserved for an eager push; false makes it due for the relay.
     *
     * @param  list<int>  $entryIds
     * @param  callable(?string): bool  $directFor
     * @return list<array{entryId: int, branchId: string, connection: ?string, queue: ?string, direct: bool}>
     */
    public function releaseHeldBranches(array $entryIds, callable $directFor): array
    {
        if ($entryIds === []) {
            return [];
        }

        $rows = $this->heldQuery()->whereIn('id', $entryIds)->orderBy('id')->get();
        $now = $this->dueAt(Carbon::now('UTC'));
        $released = [];

        foreach ($rows as $row) {
            $branchId = $this->payloadBranchId($row);

            if ($branchId === null) {
                continue;
            }

            $connection = $row->queue_connection ?: null;
            $queue = $row->queue_name ?: null;
            $direct = $directFor($connection);

            $this->table()->where('id', $row->id)->update([
                'payload' => json_encode($direct ? ['branch_id' => $branchId, 'direct' => true] : ['branch_id' => $branchId]),
                'available_at' => $now,
                'reserved_at' => $direct ? $now : null,
            ]);

            $released[] = ['entryId' => (int) $row->id, 'branchId' => $branchId, 'connection' => $connection, 'queue' => $queue, 'direct' => $direct];
        }

        return $released;
    }

    /**
     * Delete held rows whose branch no longer needs them (it already finished —
     * e.g. older code ran it after a rollback).
     *
     * @param  list<int>  $entryIds
     */
    public function discardHeldBranches(array $entryIds): void
    {
        if ($entryIds !== []) {
            $this->heldQuery()->whereIn('id', $entryIds)->delete();
        }
    }

    /**
     * Run ids with at least one held row, in run id order, for the recovery sweep;
     * optionally only runs of one swarm class (joined in SQL).
     *
     * @return list<string>
     */
    public function runsWithHeldBranches(?string $runId = null, int $limit = 500, ?string $afterRunId = null, ?string $swarmClass = null): array
    {
        $query = $this->heldQuery()->select('run_id')->groupBy('run_id')->orderBy('run_id')->limit($limit);

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        if ($afterRunId !== null) {
            $query->where('run_id', '>', $afterRunId);
        }

        if ($swarmClass !== null) {
            $query->whereIn('run_id', fn (Builder $runs) => $runs
                ->from((string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs'))
                ->select('run_id')
                ->where('swarm_class', $swarmClass));
        }

        return array_values($query->pluck('run_id')->map(static fn (mixed $id): string => (string) $id)->all());
    }

    /**
     * Held branch ids for many runs in one query, as run id => branch ids.
     *
     * @param  list<string>  $runIds
     * @return array<string, list<string>>
     */
    public function heldBranchesForRuns(array $runIds): array
    {
        $held = [];

        foreach ($this->heldQuery()->whereIn('run_id', $runIds)->orderBy('id')->get(['run_id', 'payload']) as $row) {
            $branchId = $this->payloadBranchId($row);

            if ($branchId !== null) {
                $held[(string) $row->run_id][] = $branchId;
            }
        }

        return $held;
    }

    /**
     * Constrain a query on this table to rows that are NOT held branch rows. Step,
     * resume and released branch rows are never excluded, whatever their timestamps.
     */
    public function whereNotHeld(Builder $query): Builder
    {
        return self::constrainNotHeld($query);
    }

    /**
     * Static twin of whereNotHeld() for operator commands that query the outbox
     * table on their own connection. Apply it inside a nested where().
     */
    public static function constrainNotHeld(Builder $query): Builder
    {
        return $query->where('dispatch_type', '!=', DurableDispatchType::Branch->value)
            ->orWhere('available_at', '!=', self::HOLD_UNTIL);
    }

    /**
     * Constrain an outbox-table query to held branch rows only.
     */
    public static function constrainHeld(Builder $query): Builder
    {
        return $query->where('dispatch_type', DurableDispatchType::Branch->value)
            ->where('available_at', self::HOLD_UNTIL);
    }

    /**
     * Whether this outbox writes through the given connection instance — the
     * fan-out cap needs the run store, the outbox and the durable runtime to share
     * one transaction owner.
     */
    public function usesConnection(Connection $connection): bool
    {
        return $this->connection === $connection;
    }

    /**
     * The timestamp an ordinary (not held) write stores as available_at. Compared at
     * the persisted, whole-second precision: the one second equal to HOLD_UNTIL is
     * stepped back so no ordinary row can ever read as held.
     */
    public function dueAt(Carbon $now): Carbon
    {
        $now = $now->copy()->startOfSecond();

        return $now->format('Y-m-d H:i:s') === self::HOLD_UNTIL ? $now->subSecond() : $now;
    }

    protected function heldQuery(): Builder
    {
        return self::constrainHeld($this->table());
    }

    protected function payloadBranchId(object $row): ?string
    {
        $payload = is_string($row->payload) ? json_decode($row->payload, true) : (array) $row->payload;
        $branchId = is_array($payload) ? ($payload['branch_id'] ?? null) : null;

        return is_string($branchId) && $branchId !== '' ? $branchId : null;
    }

    protected function writtenForDirectDispatch(object $entry): bool
    {
        $payload = is_string($entry->payload) ? json_decode($entry->payload, true) : (array) $entry->payload;

        return is_array($payload) && ($payload['direct'] ?? false) === true;
    }

    protected function table(): Builder
    {
        return $this->connection->table(
            (string) $this->config->get('swarm.tables.durable_outbox', 'swarm_durable_outbox'),
        );
    }
}
