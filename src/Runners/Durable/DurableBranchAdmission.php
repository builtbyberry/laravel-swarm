<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use RuntimeException;
use Throwable;

/**
 * The durable branch fan-out cap (swarm.durable.parallel.max_concurrent_branches).
 *
 * A capped fan-out dispatches its first N branches and writes the rest as HELD
 * outbox rows (DatabaseDurableOutbox::HOLD_UNTIL). Admission is a reconcile, not a
 * hand-off: under a lock on the run row it counts the fan-out's admitted unfinished
 * branches and releases held rows into the free slots. It is idempotent, so a
 * reconcile that is lost (a crash after a branch's commit) is repaired by the next
 * one — after the next branch finishes, on resume, or by swarm:recover.
 *
 * Reconcile never runs inside a caller's transaction: Laravel nests transactions as
 * savepoints, so a nested reconcile would read the caller's older snapshot and its
 * effects would not be committed. It runs on the swarm connection's outermost
 * commit, in its own transaction. Branch terminal writes are never touched by it.
 *
 * Held branches are also refused at execution time (isHeld()), so a stale or
 * duplicate job — including one from an earlier loop pass with the same branch id —
 * can never run a branch the cap is holding back.
 *
 * The cap is supported only with the bundled database run store and outbox writing
 * through the same connection instance as the durable runtime: admission relies on
 * the fan-out's branch rows and held rows committing in one transaction.
 *
 * @internal
 */
class DurableBranchAdmission
{
    use SafeReporting;

    /** Runs per page of the recovery sweep over runs that hold branches. */
    protected const SWEEP_PAGE = 500;

    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected DurableOutbox $outbox,
        protected Connection $connection,
        protected DurableEagerDispatcher $eager,
        protected Container $container,
    ) {}

    /**
     * The configured cap, or null for unlimited. Null and '' (an env var set but
     * empty) mean unlimited; anything that is not a positive integer throws.
     */
    public function cap(): ?int
    {
        $value = $this->config->get('swarm.durable.parallel.max_concurrent_branches');

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\s*[1-9][0-9]*\s*$/', $value) === 1) {
            return (int) trim($value);
        }

        throw new SwarmException(
            'swarm.durable.parallel.max_concurrent_branches must be null (unlimited) or a positive integer, got ['
            .(is_scalar($value) ? (string) $value : get_debug_type($value)).'].'
        );
    }

    /**
     * Whether the bound persistence supports holding branches: the bundled run store
     * and outbox, both on this runtime's connection instance.
     */
    public function supported(): bool
    {
        return $this->durableRuns instanceof DatabaseDurableRunStore
            && $this->outbox instanceof DatabaseDurableOutbox
            && $this->durableRuns->usesConnection($this->connection)
            && $this->outbox->usesConnection($this->connection);
    }

    /**
     * The cap a fan-out applies, validated: throws when a cap is configured but
     * invalid or unsupported. Called when a durable run starts and again before a
     * fan-out writes anything.
     */
    public function capForFanOut(): ?int
    {
        $cap = $this->cap();

        if ($cap !== null && ! $this->supported()) {
            throw new SwarmException(
                'swarm.durable.parallel.max_concurrent_branches requires the bundled database durable run store and '
                .'outbox (DatabaseDurableRunStore and DatabaseDurableOutbox) on the same database connection as the '
                .'durable runtime. Unset the cap or restore the bundled bindings.'
            );
        }

        return $cap;
    }

    /**
     * Hold a branch the cap does not admit at fan-out. Call inside the fan-out's
     * checkpoint transaction, only after capForFanOut() returned a cap.
     */
    public function hold(string $runId, string $branchId, ?string $connection, ?string $queue): int
    {
        return $this->databaseOutbox()->holdBranch($runId, $branchId, $connection, $queue);
    }

    /**
     * Whether a branch lease must be checked against held rows. False only when no
     * cap is configured, the lease is not nested in a caller's transaction, and the
     * run holds no branches — the default install, which then pays one indexed read
     * and takes no lock. Held rows only ever come from a committed capped fan-out, so
     * a plain read at the top level sees every one that could matter.
     */
    public function leaseNeedsHeldCheck(string $runId): bool
    {
        if (! $this->supported()) {
            return false;
        }

        if ($this->connection->transactionLevel() > 0) {
            return true;
        }

        try {
            if ($this->cap() !== null) {
                return true;
            }
        } catch (Throwable) {
            // An invalid cap appeared mid-run: keep refusing held branches.
            return true;
        }

        return $this->databaseOutbox()->heldBranches($runId) !== [];
    }

    /**
     * Whether the branch is held. Call after the branch lease UPDATE, inside the same
     * transaction, and roll back when it returns true.
     *
     * When that transaction is the outermost one, an ordinary read is already current:
     * the lease UPDATE was its first statement, so MySQL REPEATABLE READ takes the
     * snapshot after it and PostgreSQL READ COMMITTED reads per statement. Only when
     * the lease is nested in a caller's transaction — whose snapshot may predate the
     * hold — is a locking (current) read needed, so the shared lock is confined to
     * that rare case.
     */
    public function isHeld(string $runId, string $branchId): bool
    {
        if (! $this->supported()) {
            return false;
        }

        $lock = $this->connection->transactionLevel() > 1;

        return in_array($branchId, $this->databaseOutbox()->heldBranches($runId, lock: $lock), true);
    }

    /**
     * Held branch ids of one run (resume skips them when redispatching).
     *
     * @return list<string>
     */
    public function heldBranchIds(string $runId): array
    {
        if (! $this->supported()) {
            return [];
        }

        return array_values($this->databaseOutbox()->heldBranches($runId));
    }

    /**
     * Run ids => held branch ids, for recovery's held-branch exclusion: one query
     * for the whole set.
     *
     * @param  list<string>  $runIds
     * @return array<string, list<string>>
     */
    public function heldBranchIdsFor(array $runIds): array
    {
        if (! $this->supported() || $runIds === []) {
            return [];
        }

        return $this->databaseOutbox()->heldBranchesForRuns($runIds);
    }

    /**
     * Reconcile the run once the swarm connection's outermost transaction commits
     * (now, if none is open). Dropped if that transaction rolls back. When an open
     * transaction cannot be tracked, nothing runs: the held rows stay held and
     * swarm:recover reconciles them.
     */
    public function reconcileAfterCommit(string $runId): void
    {
        if (! $this->supported()) {
            return;
        }

        $run = function () use ($runId): void {
            // Re-checked when the callback fires: only ever reconcile at level zero.
            if ($this->connection->transactionLevel() !== 0) {
                return;
            }

            $this->reconcileReporting($runId);
        };

        if ($this->connection->transactionLevel() === 0) {
            $run();

            return;
        }

        $transactions = $this->container->bound('db.transactions') ? $this->container->make('db.transactions') : null;

        if (! $transactions instanceof DatabaseTransactionsManager) {
            return;
        }

        // Attach to the innermost pending transaction of the swarm connection, so a
        // savepoint or outer rollback discards it — but only when the manager tracks
        // every open transaction on this connection; otherwise leave it to recovery.
        $name = $this->connection->getName();
        $pending = $transactions->getPendingTransactions()
            ->filter(fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $name);
        $record = $pending->last();

        if ($record instanceof DatabaseTransactionRecord && $pending->count() === $this->connection->transactionLevel()) {
            $record->addCallback($run);
        }
    }

    /**
     * Reconcile every waiting run that holds branches (swarm:recover). Visits each
     * qualifying run once, paging through run ids; a failure on one run is reported
     * and the sweep continues. Not bounded by recover's --limit: a run is only ever
     * filled up to its cap, so visiting all of them cannot over-admit.
     *
     * @return list<string> run ids that released at least one branch
     */
    public function reconcileHeldRuns(?string $runId = null, ?string $swarmClass = null): array
    {
        if (! $this->supported() || $this->connection->transactionLevel() !== 0) {
            return [];
        }

        $outbox = $this->databaseOutbox();
        $released = [];
        $after = null;

        do {
            try {
                $page = $outbox->runsWithHeldBranches($runId, self::SWEEP_PAGE, $after, $swarmClass);
            } catch (Throwable $e) {
                // The sweep is recovery's liveness net for the cap; it must never stop
                // the rest of swarm:recover (e.g. a misconfigured outbox table).
                $this->safeReport(new RuntimeException('Durable branch admission could not list runs holding branches; held branches are not reconciled this sweep.', previous: $e));

                return $released;
            }

            foreach ($page as $heldRunId) {
                if ($this->reconcileReporting($heldRunId) > 0) {
                    $released[] = $heldRunId;
                }
            }

            $after = $page === [] ? null : $page[array_key_last($page)];
        } while (count($page) === self::SWEEP_PAGE);

        return $released;
    }

    /**
     * Release held branches into the run's free slots. Runs in its own top-level
     * transaction whose first statement locks the run row. Returns how many branches
     * it released.
     */
    public function reconcile(string $runId): int
    {
        if (! $this->supported()) {
            return 0;
        }

        if ($this->connection->transactionLevel() !== 0) {
            throw new RuntimeException('Durable branch admission must not reconcile inside an open transaction.');
        }

        $store = $this->databaseRunStore();
        $outbox = $this->databaseOutbox();

        // Cheap exit before any lock: a run with no held rows has nothing to release,
        // which is every run when the cap is unset. Held rows are only written by a
        // committed fan-out, so a plain read cannot miss one that matters here.
        if ($outbox->heldBranches($runId) === []) {
            return 0;
        }

        $released = $this->connection->transaction(function () use ($runId, $store, $outbox): array {
            $run = $store->lockRunForAdmission($runId);

            if ($run === null) {
                return [];
            }

            $held = $outbox->heldBranches($runId);

            if ($held === []) {
                return [];
            }

            // A finished run's held rows can never run (cancel and fail cancel its
            // pending branches); drop them so the recovery sweep stops visiting it.
            if (in_array($run['status'], ['completed', 'failed', 'cancelled'], true)) {
                $outbox->discardHeldBranches(array_keys($held));

                return [];
            }

            if ($run['status'] !== 'waiting' || $run['current_node_id'] === null) {
                return [];
            }

            $statuses = $store->branchStatusesFor($runId, $run['current_node_id']);
            $unfinished = array_keys(array_filter(
                $statuses,
                static fn (string $status): bool => ! in_array($status, ['completed', 'failed', 'cancelled'], true),
            ));
            $unfinished = array_map('strval', $unfinished);

            // Held rows whose branch is no longer waiting to run (finished, or not part
            // of this fan-out) are discarded, never counted or released.
            $stale = [];
            $waiting = [];

            foreach ($held as $entryId => $branchId) {
                if (in_array($branchId, $unfinished, true)) {
                    $waiting[$entryId] = $branchId;
                } else {
                    $stale[] = $entryId;
                }
            }

            $outbox->discardHeldBranches($stale);

            $cap = $this->cap();
            $admitted = count(array_diff($unfinished, array_values($waiting)));
            $free = $cap === null ? count($waiting) : max(0, $cap - $admitted);

            if ($free === 0) {
                return [];
            }

            $toRelease = array_slice(array_keys($waiting), 0, $free);

            // Restart the released branches' recovery grace now: otherwise they still
            // carry the fan-out's updated_at and swarm:recover would treat them as
            // crashed and dispatch duplicates before they are leased. Branch rows are
            // written before outbox rows, the order a lease takes them in.
            $store->touchBranches($runId, array_values(array_intersect_key($waiting, array_flip($toRelease))));

            $released = $outbox->releaseHeldBranches(
                $toRelease,
                fn (?string $connection): bool => $this->eager->directFor($connection),
            );

            foreach ($released as $entry) {
                if ($entry['direct']) {
                    $this->eager->branch($entry['entryId'], $runId, $entry['branchId'], $entry['connection'], $entry['queue']);
                }
            }

            return $released;
        });

        return count($released);
    }

    protected function reconcileReporting(string $runId): int
    {
        try {
            return $this->reconcile($runId);
        } catch (Throwable $e) {
            $this->safeReport(new RuntimeException(
                "Durable branch admission failed to reconcile run [{$runId}]; its held branches stay held until the next branch finishes or swarm:recover runs.",
                previous: $e,
            ));

            return 0;
        }
    }

    protected function databaseRunStore(): DatabaseDurableRunStore
    {
        if (! $this->durableRuns instanceof DatabaseDurableRunStore) {
            throw new RuntimeException('Durable branch admission requires DatabaseDurableRunStore.');
        }

        return $this->durableRuns;
    }

    protected function databaseOutbox(): DatabaseDurableOutbox
    {
        if (! $this->outbox instanceof DatabaseDurableOutbox) {
            throw new RuntimeException('Durable branch admission requires DatabaseDurableOutbox.');
        }

        return $this->outbox;
    }
}
