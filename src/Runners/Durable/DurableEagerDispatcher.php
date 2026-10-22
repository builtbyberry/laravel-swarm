<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use RuntimeException;
use Throwable;

/**
 * Pushes a durable advance job the moment the swarm transaction that wrote its
 * outbox entry commits, instead of waiting for swarm:relay (swarm.durable.eager_dispatch).
 *
 * Transaction boundary (DB then queue). A queue push cannot join a database
 * transaction, so this is deliberately eventually consistent, in this order:
 *
 *   1. The outbox entry is written inside the caller's swarm transaction with
 *      directDispatch, so the entry is born reserved and the relay leaves it alone.
 *   2. The push runs only after the swarm connection's outermost transaction
 *      commits, and is discarded if that transaction (or the savepoint that wrote
 *      the entry) rolls back — a worker can never see a job before its entry and
 *      state change are durable.
 *   3. The pushed job deletes the entry once it holds its lease.
 *
 * A failed, lost or skipped push is not an error to surface to the step: the
 * entry stays reserved, its reservation goes stale and the relay's ordinary
 * stale-reclaim dispatches it. A second push for the same step or branch is a
 * no-op because lease acquisition is a conditional update.
 *
 * enqueueStep() and enqueueBranch() decide once (directFor()) and use that decision
 * for both the write and the push, so a born-reserved entry is always pushed and a
 * relay-only entry never is. The checkpoint coordinator alone writes and pushes
 * separately (it must hold its pushes until the durable boundary is entered); it
 * records directFor() per entry at write time and calls step() / branch(), which
 * never re-decide.
 *
 * @internal
 */
class DurableEagerDispatcher
{
    use SafeReporting;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected DurableJobDispatcher $jobs,
        protected Dispatcher $bus,
        protected DurableOutbox $outbox,
        protected Container $container,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('swarm.durable.eager_dispatch', false);
    }

    /**
     * Whether an entry bound for this queue connection is written for direct
     * dispatch. Never on the sync driver: a push there runs the job inline, so every
     * step would run nested inside the previous one and inside whatever request
     * committed it. Those entries stay plain relay entries, exactly as with the flag off.
     */
    public function directFor(?string $queueConnection): bool
    {
        return $this->enabled() && $this->queueDriver($queueConnection) !== 'sync';
    }

    /**
     * Write a step entry and, with eager dispatch on, push its job after commit.
     * Call inside the transaction that makes the step runnable.
     */
    public function enqueueStep(string $runId, int $stepIndex, ?string $connection, ?string $queue): int
    {
        $direct = $this->directFor($connection);
        $entryId = $this->outbox->enqueueStep($runId, $stepIndex, $connection, $queue, $direct);

        if ($direct) {
            $this->step($entryId, $runId, $stepIndex, $connection, $queue);
        }

        return $entryId;
    }

    /**
     * Write a branch entry and, with eager dispatch on, push its job after commit.
     * Call inside the transaction that makes the branch runnable.
     */
    public function enqueueBranch(string $runId, string $branchId, ?string $connection, ?string $queue): int
    {
        $direct = $this->directFor($connection);
        $entryId = $this->outbox->enqueueBranch($runId, $branchId, $connection, $queue, $direct);

        if ($direct) {
            $this->branch($entryId, $runId, $branchId, $connection, $queue);
        }

        return $entryId;
    }

    /**
     * Push the job for a step entry already written for direct dispatch.
     */
    public function step(int $entryId, string $runId, int $stepIndex, ?string $connection, ?string $queue): void
    {
        $this->afterSwarmCommit(
            fn () => $this->jobs->makeStepJob($runId, $stepIndex, $connection, $queue, $entryId),
            "entry [{$entryId}] for run [{$runId}] step [{$stepIndex}] on queue connection [".($connection ?? 'default').'], queue ['.($queue ?? 'default').']',
        );
    }

    /**
     * Push the job for a branch entry already written for direct dispatch.
     */
    public function branch(int $entryId, string $runId, string $branchId, ?string $connection, ?string $queue): void
    {
        $this->afterSwarmCommit(
            fn () => $this->jobs->makeBranchJob($runId, $branchId, $connection, $queue, $entryId),
            "entry [{$entryId}] for run [{$runId}] branch [{$branchId}] on queue connection [".($connection ?? 'default').'], queue ['.($queue ?? 'default').']',
        );
    }

    /**
     * Connection::afterCommit() is not used: it hands the callback to the shared
     * transactions manager, which attaches it to the last pending transaction on
     * ANY connection — an application transaction opened on another connection
     * inside the swarm one would fire the push before the swarm commit. Attach to
     * the innermost pending transaction of the swarm connection instead.
     *
     * @param  callable(): (AdvanceDurableSwarm|AdvanceDurableBranch)  $makeJob
     */
    protected function afterSwarmCommit(callable $makeJob, string $describe): void
    {
        $push = function () use ($makeJob, $describe): void {
            try {
                // The swarm commit has already happened. beforeCommit() stops the
                // queue from deferring the push again onto some other connection's
                // open transaction — native jobs set afterCommit(), and a queue
                // connection may set after_commit — where a rollback would drop it
                // and a push failure would escape this handler.
                $this->bus->dispatch($makeJob()->beforeCommit());
            } catch (Throwable $e) {
                $this->safeReport(new RuntimeException(
                    "Eager durable dispatch failed for {$describe}; swarm:relay will dispatch it once its reservation goes stale.",
                    previous: $e,
                ));
            }
        };

        // Resolved per push, not captured: the testing traits rebind db.transactions
        // (and each connection's manager) after the durable graph is built.
        $transactions = $this->container->bound('db.transactions') ? $this->container->make('db.transactions') : null;
        $name = $this->connection->getName();

        if ($transactions instanceof DatabaseTransactionsManager) {
            $record = $transactions->callbackApplicableTransactions()
                ->filter(fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $name)
                ->last();

            if ($record instanceof DatabaseTransactionRecord) {
                $record->addCallback($push);

                return;
            }

            // No applicable record: either nothing is open, or only transactions the
            // manager deliberately excludes (a test wrapper) — the work is committed
            // as far as the application is concerned. If the manager cannot see every
            // open transaction on this connection, it is not the one tracking it.
            $tracked = $transactions->getPendingTransactions()
                ->filter(fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $name)
                ->count();

            if ($tracked >= $this->connection->transactionLevel()) {
                $push();
            }

            return;
        }

        // Without a manager there is no way to wait for the commit: push only when
        // nothing is open, otherwise leave the reserved entry to the relay.
        if ($this->connection->transactionLevel() === 0) {
            $push();
        }
    }

    protected function queueDriver(?string $queueConnection): ?string
    {
        $name = $queueConnection ?? $this->config->get('queue.default');
        $driver = is_string($name) ? $this->config->get("queue.connections.{$name}.driver") : null;

        return is_string($driver) ? $driver : null;
    }
}
