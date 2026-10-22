<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Throwable;

/**
 * Pushes a durable advance job the moment the swarm transaction that wrote its
 * outbox entry commits, instead of waiting for swarm:relay (swarm.durable.eager_dispatch).
 *
 * Transaction boundary (DB then queue). A queue push cannot join a database
 * transaction, so this is deliberately eventually consistent, in this order:
 *
 *   1. The caller writes the outbox entry inside its swarm transaction with
 *      directDispatch, so the entry is born reserved and the relay leaves it alone.
 *   2. The push runs only after the swarm connection's outermost transaction
 *      commits, and is discarded if that transaction (or the savepoint that wrote
 *      the entry) rolls back — a worker can never see a job before its entry and
 *      state change are durable.
 *   3. The pushed job deletes the entry once it holds its lease.
 *
 * A failed or lost push is not an error to surface to the step: the entry stays
 * reserved, its reservation goes stale and the relay's ordinary stale-reclaim
 * dispatches it. A second push for the same step or branch is a no-op because
 * lease acquisition is a conditional update.
 *
 * The flag is read by callers once per operation (enabled()) and passed to the
 * outbox as directDispatch; step() and branch() never re-read it, so a born-reserved
 * entry is always pushed and a relay-only entry never is.
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
        protected ?DatabaseTransactionsManager $transactions = null,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('swarm.durable.eager_dispatch', false);
    }

    public function step(int $entryId, string $runId, int $stepIndex, ?string $connection, ?string $queue): void
    {
        $this->afterSwarmCommit(fn () => $this->jobs->makeStepJob($runId, $stepIndex, $connection, $queue, $entryId));
    }

    public function branch(int $entryId, string $runId, string $branchId, ?string $connection, ?string $queue): void
    {
        $this->afterSwarmCommit(fn () => $this->jobs->makeBranchJob($runId, $branchId, $connection, $queue, $entryId));
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
    protected function afterSwarmCommit(callable $makeJob): void
    {
        $push = function () use ($makeJob): void {
            try {
                // The swarm commit has already happened. beforeCommit() stops the
                // queue from deferring the push again onto some other connection's
                // open transaction — native jobs set afterCommit(), and a queue
                // connection may set after_commit — where a rollback would drop it
                // and a push failure would escape this handler.
                $this->bus->dispatch($makeJob()->beforeCommit());
            } catch (Throwable $e) {
                $this->safeReport($e);
            }
        };

        if ($this->transactions === null) {
            // Without the manager there is no way to wait for the swarm commit, so
            // push only when nothing is pending; otherwise leave the reserved entry
            // to the relay rather than risk pushing before the commit.
            if ($this->connection->transactionLevel() === 0) {
                $push();
            }

            return;
        }

        $record = $this->transactions->callbackApplicableTransactions()
            ->filter(fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $this->connection->getName())
            ->last();

        if ($record instanceof DatabaseTransactionRecord) {
            $record->addCallback($push);

            return;
        }

        $push();
    }
}
