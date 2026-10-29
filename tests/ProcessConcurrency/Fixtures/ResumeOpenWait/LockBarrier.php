<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cross-process barriers for the resume/wait-release lock tests. Ordering between
 * workers goes through marker files; "the other worker is now blocked on MY lock" is
 * read from the engine's own lock-wait view for the two connections involved, so a
 * test proves the contention it claims instead of hoping a sleep produced it.
 */
final class LockBarrier
{
    public static function connectionId(): int
    {
        $row = DB::connection()->getDriverName() === 'pgsql'
            ? DB::selectOne('select pg_backend_pid() as id')
            : DB::selectOne('select connection_id() as id');

        return (int) $row->id;
    }

    public static function signal(string $dir, string $name, string $value = '1'): void
    {
        file_put_contents($dir.'/'.$name, $value);
    }

    public static function await(string $dir, string $name, float $timeoutSeconds = 20.0): string
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (! is_file($dir.'/'.$name)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Timed out waiting for barrier [{$name}] in [{$dir}].");
            }

            usleep(2_000);
        }

        return (string) file_get_contents($dir.'/'.$name);
    }

    public static function isBlockedBy(int $waiter, int $blocker): bool
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return (bool) DB::selectOne('select ? = any(pg_blocking_pids(?)) as blocked', [$blocker, $waiter])->blocked;
        }

        // performance_schema rather than sys.innodb_lock_waits: the sys view joins through
        // information_schema.innodb_trx, which does not list a transaction that has only
        // asked for a shared lock — exactly the resume being observed here. The blocker
        // is matched by its InnoDB transaction id, not its thread: when the waiter's
        // request converts the blocker's implicit row lock into an explicit one, InnoDB
        // records the waiter's thread as that lock's owner thread. Must be called on the
        // blocker's own connection, inside the transaction that holds the lock.
        $blockerTrx = DB::selectOne(
            'select trx_id from information_schema.innodb_trx where trx_mysql_thread_id = ?',
            [$blocker],
        )?->trx_id;

        if ($blockerTrx === null) {
            return false;
        }

        return (int) DB::selectOne(
            'select count(*) as waits from performance_schema.data_lock_waits w '
            .'join performance_schema.threads rt on rt.thread_id = w.requesting_thread_id '
            .'where rt.processlist_id = ? and w.blocking_engine_transaction_id = ?',
            [$waiter, $blockerTrx],
        )->waits > 0;
    }

    /**
     * What the engine reports about lock waits right now — returned with a barrier
     * timeout so a "contention not established" failure says why.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lockWaitSnapshot(): array
    {
        $rows = DB::connection()->getDriverName() === 'pgsql'
            ? DB::select('select pid, pg_blocking_pids(pid) as blocked_by, wait_event_type, state, left(query, 120) as query from pg_stat_activity where datname = current_database()')
            : DB::select('select rt.processlist_id as waiting_pid, bt.processlist_id as blocking_pid, l.object_name, l.index_name, l.lock_mode, l.lock_data from performance_schema.data_lock_waits w left join performance_schema.data_locks l on l.engine_lock_id = w.requesting_engine_lock_id left join performance_schema.threads rt on rt.thread_id = w.requesting_thread_id left join performance_schema.threads bt on bt.thread_id = w.blocking_thread_id');

        return array_map(fn (object $row): array => (array) $row, $rows);
    }

    /**
     * Poll until $waiter is waiting on a lock $blocker holds. False on timeout: the
     * caller fails the test ("contention not established") rather than passing it.
     */
    public static function awaitBlockedBy(int $waiter, int $blocker, float $timeoutSeconds = 15.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (self::isBlockedBy($waiter, $blocker)) {
                return true;
            }

            usleep(10_000);
        }

        return false;
    }

    /**
     * The SQLSTATE of a deadlock abort (MySQL 1213 → 40001, Postgres 40P01), or the
     * exception class for anything else. Laravel wraps a deadlock inside a nested
     * transaction in a DeadlockException whose own code is 0, so the chain is walked to
     * the driver's exception.
     */
    public static function failure(\Throwable $e): string
    {
        $previous = $e;

        while ($previous !== null) {
            if ($previous instanceof \PDOException && (string) $previous->getCode() !== '0') {
                return 'sqlstate:'.(string) $previous->getCode();
            }

            $previous = $previous->getPrevious();
        }

        return $e::class.': '.$e->getMessage();
    }
}
