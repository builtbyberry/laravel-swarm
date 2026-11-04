<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence\Concerns;

use Illuminate\Database\Query\Builder;

/**
 * @internal
 */
trait ConfirmsHeldLease
{
    /**
     * Whether a lease-guarded write that changed no row still matches its guard.
     *
     * MySQL reports changed rows, not matched rows (Laravel does not set
     * PDO::MYSQL_ATTR_FOUND_ROWS), so a write whose values all equal the stored
     * ones (an unchanged current_node_id, or a lease renewal within the same
     * second as the previous write) returns 0 while the lease is still held.
     * Postgres and SQLite report matched rows, so there 0 means the lease is gone
     * and this finds no row.
     *
     * Pass the guarded builder the write ran on, with the same timestamp in its
     * lease predicate. The re-read is a locking read so it sees the latest
     * committed row, not the caller's transaction snapshot: under REPEATABLE READ
     * a plain read could still show a lease another worker has since taken.
     * Inside a transaction, the lock then holds the row for the rest of the
     * caller's writes; an autocommit caller gets the ownership check only.
     */
    protected function stillHoldsLease(Builder $guarded): bool
    {
        return (clone $guarded)->lockForUpdate()->first(['run_id']) !== null;
    }
}
