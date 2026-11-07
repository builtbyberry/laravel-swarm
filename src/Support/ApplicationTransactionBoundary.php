<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use Closure;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Runs package work that may be called inside an application's own database transaction
 * so that, when it fails, its writes are undone and the application's transaction stays
 * usable.
 *
 * Laravel rolls a nested transaction back to its savepoint on most errors, but on a
 * concurrency error (a deadlock, a MySQL lock-wait timeout) it rethrows without rolling
 * back, on the premise that the database has aborted the whole transaction. A lock-wait
 * timeout aborts only the statement, so an application that caught the exception and
 * committed would commit half of the package's work. A savepoint taken here, outside
 * Laravel's transaction counter, is rolled back on any failure. After a real deadlock
 * MySQL has already rolled the whole transaction back and the savepoint is gone: that
 * rollback's own failure is reported to $onRollbackFailure and the original error is
 * rethrown.
 *
 * Outside a transaction it only runs the work: the work's own transaction is then the
 * outermost one, and Laravel rolls that back whole.
 *
 * @internal
 */
final class ApplicationTransactionBoundary
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @param  (Closure(Throwable): void)|null  $onRollbackFailure
     * @return TResult
     */
    public static function run(Connection $connection, Closure $work, ?Closure $onRollbackFailure = null): mixed
    {
        if ($connection->transactionLevel() === 0) {
            return $work();
        }

        // Issued on the PDO, as Laravel issues its own savepoints. The name is generated,
        // never user input.
        $grammar = $connection->getQueryGrammar();
        $savepoint = 'swarm_boundary_'.bin2hex(random_bytes(6));
        $connection->getPdo()->exec($grammar->compileSavepoint($savepoint));

        try {
            $result = $work();
        } catch (Throwable $exception) {
            try {
                $connection->getPdo()->exec($grammar->compileSavepointRollBack($savepoint));
            } catch (Throwable $rollbackFailure) {
                if ($onRollbackFailure !== null) {
                    $onRollbackFailure($rollbackFailure);
                }
            }

            throw $exception;
        }

        // Released so an application transaction that runs this many times does not
        // accumulate savepoints (on Postgres, subtransactions) until it commits. SQL
        // Server has no savepoint release; its savepoints end with the transaction.
        if ($connection->getDriverName() !== 'sqlsrv') {
            $connection->getPdo()->exec('RELEASE SAVEPOINT '.$savepoint);
        }

        return $result;
    }
}
