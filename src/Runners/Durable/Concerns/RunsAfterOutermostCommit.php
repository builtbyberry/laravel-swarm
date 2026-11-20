<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable\Concerns;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;

/**
 * Run work once the swarm connection's OUTERMOST transaction has committed — at
 * once when no transaction is open. A savepoint or outer rollback discards it.
 * When the transaction manager does not track every open level of the swarm
 * connection, nothing is attached and the caller's recovery path does the work.
 *
 * The using class provides the swarm `Connection` as `$connection` and the
 * container as `$container`.
 *
 * @internal
 *
 * @property Connection $connection
 * @property Container $container
 */
trait RunsAfterOutermostCommit
{
    protected function runAfterOutermostCommit(Closure $work): void
    {
        $run = function () use ($work): void {
            // Re-checked when the callback fires: only ever run at level zero.
            if ($this->connection->transactionLevel() !== 0) {
                return;
            }

            $work();
        };

        if ($this->connection->transactionLevel() === 0) {
            $run();

            return;
        }

        // Resolved per call, not injected: the testing traits rebind it after the
        // durable graph is built.
        $transactions = $this->container->bound('db.transactions') ? $this->container->make('db.transactions') : null;

        if (! $transactions instanceof DatabaseTransactionsManager) {
            return;
        }

        // Attach to the innermost pending transaction of the swarm connection, so a
        // savepoint or outer rollback discards it — but only when the manager tracks
        // every open transaction on this connection.
        $name = $this->connection->getName();
        $pending = $transactions->getPendingTransactions()
            ->filter(fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $name);
        $record = $pending->last();

        if ($record instanceof DatabaseTransactionRecord && $pending->count() === $this->connection->transactionLevel()) {
            $record->addCallback($run);
        }
    }
}
