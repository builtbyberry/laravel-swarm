<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\AcceptsDurableSignals;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmSignalled;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Throwable;

/**
 * @internal
 */
class DurableSignalHandler
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected Dispatcher $events,
        protected SwarmCapture $capture,
        protected DurableRunContext $runs,
        protected DurablePayloadCapture $payloads,
        protected SwarmAuditDispatcher $audit,
        protected Connection $connection,
        protected DurableEagerDispatcher $eager,
        protected DurableJoinRelease $joinRelease,
    ) {}

    public function signal(string $runId, string $name, mixed $payload = null, ?string $idempotencyKey = null): DurableSignalResult
    {
        $run = $this->runs->requireRun($runId);
        $capturedPayload = $this->payloads->payload($payload);
        $signal = null;
        $accepted = false;

        if ($run['status'] === 'waiting' && $this->durableRuns instanceof AcceptsDurableSignals) {
            // The signal row is written inside the acceptance transaction, after the wait
            // and run rows are locked: an acceptance that aborts leaves no signal behind,
            // so the same idempotency key is accepted when it is sent again.
            $store = $this->durableRuns;
            [$signal, $accepted] = $this->withinAcceptanceBoundary(fn (): array => $this->connection->transaction(function () use ($store, $runId, $name, $capturedPayload, $idempotencyKey): array {
                $signal = $store->acceptSignal($runId, $name, $capturedPayload, $idempotencyKey);

                if ($signal === null) {
                    return [null, false];
                }

                $released = ($signal['released'] ?? false) === true;
                unset($signal['released']);

                if ($released) {
                    $this->publishAcceptance($runId, $name, (int) $signal['id'], $capturedPayload);
                }

                return [$signal, $released];
            }));
        }

        if ($signal === null) {
            // No open wait of this name, a run that is not waiting, or a store without
            // atomic acceptance: the signal is recorded on its own. With such a store the
            // acceptance follows in its own transaction, and an acceptance that aborts
            // leaves the recorded signal behind (see AcceptsDurableSignals).
            $signal = $this->durableRuns->recordSignal($runId, $name, $capturedPayload, $idempotencyKey);

            if (! $this->durableRuns instanceof AcceptsDurableSignals && ($signal['duplicate'] ?? false) !== true && $run['status'] === 'waiting') {
                $accepted = $this->withinAcceptanceBoundary(fn (): bool => $this->connection->transaction(function () use ($runId, $name, $signal, $capturedPayload): bool {
                    if (! $this->durableRuns->releaseWaitWithSignal($runId, $name, (int) $signal['id'])) {
                        return false;
                    }

                    $this->publishAcceptance($runId, $name, (int) $signal['id'], $capturedPayload);

                    return true;
                }));
            }
        }

        $this->events->dispatch(new SwarmSignalled(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            signalName: $name,
            accepted: $accepted,
            executionMode: $this->runs->publicLifecycleExecutionMode($run),
        ));
        $this->audit->emit('signal.received', [
            'run_id' => $runId,
            'swarm_class' => $run['swarm_class'],
            'topology' => $run['topology'],
            'execution_mode' => $this->runs->publicLifecycleExecutionMode($run),
            'signal_name' => $name,
            'accepted' => $accepted,
            'duplicate' => (bool) ($signal['duplicate'] ?? false),
            'status' => $accepted ? 'accepted' : (string) ($signal['status'] ?? 'recorded'),
        ]);

        return new DurableSignalResult(
            runId: $runId,
            name: $name,
            status: $accepted ? 'accepted' : (string) ($signal['status'] ?? 'recorded'),
            accepted: $accepted,
            duplicate: (bool) ($signal['duplicate'] ?? false),
            signal: $signal,
            swarmClass: (string) $run['swarm_class'],
        );
    }

    /**
     * Publish an accepted signal to the context and history and move the run on. Runs
     * inside the acceptance transaction.
     */
    protected function publishAcceptance(string $runId, string $name, int $signalId, mixed $capturedPayload): void
    {
        $context = $this->runs->loadContext($runId);
        $signals = is_array($context->metadata['durable_signals'] ?? null) ? $context->metadata['durable_signals'] : [];
        $outcomes = is_array($context->metadata['durable_wait_outcomes'] ?? null) ? $context->metadata['durable_wait_outcomes'] : [];
        $signals[$name] = ['payload' => $capturedPayload, 'signal_id' => $signalId];
        $outcomes[$name] = ['status' => 'signalled', 'payload' => $capturedPayload, 'timed_out' => false];
        $context->mergeMetadata([
            'durable_signals' => $signals,
            'durable_wait_outcomes' => $outcomes,
        ]);
        $this->contextStore->put($this->capture->activeContext($context), $this->runs->ttlSeconds());

        // History follows the run: it stays waiting while another wait is open or the
        // run is still at its branch boundary — which is what a queued-hierarchical
        // continuation lease requires — and is written before any join is released.
        $updated = $this->runs->requireRun($runId);
        $this->historyStore->syncDurableState($runId, $updated['status'], $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false);

        if ($updated['status'] === 'pending') {
            $this->eager->enqueueStep($runId, (int) $updated['next_step_index'], $updated['queue_connection'], $updated['queue_name']);
        } elseif ($updated['status'] === 'waiting') {
            // Never inside this transaction: the join release locks the run's other
            // open waits, and this transaction already holds the run row.
            $this->joinRelease->releaseAfterCommit($runId);
        }
    }

    /**
     * Run the acceptance so that, when it fails inside an application transaction, its
     * writes are undone and the application's transaction stays usable.
     *
     * Laravel rolls a nested transaction back to its savepoint on most errors, but on a
     * concurrency error (a deadlock, a MySQL lock-wait timeout) it rethrows without
     * rolling back, on the premise that the database has aborted the whole transaction.
     * A lock-wait timeout aborts only the statement, so an application that caught the
     * exception and committed would commit a half-done acceptance — a closed wait and a
     * consumed signal with no published outcome — and the same idempotency key would then
     * be a duplicate. Rolling back to a savepoint taken here undoes it. After a real
     * deadlock MySQL has already rolled the whole transaction back and the savepoint is
     * gone; that rollback's own failure is ignored and the original error rethrown.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $acceptance
     * @return TResult
     */
    protected function withinAcceptanceBoundary(Closure $acceptance): mixed
    {
        if ($this->connection->transactionLevel() === 0) {
            return $acceptance();
        }

        // Issued on the PDO, as Laravel issues its own savepoints, and outside its
        // transaction counter: the acceptance's own transaction() nests inside it.
        $grammar = $this->connection->getQueryGrammar();
        $savepoint = 'swarm_signal_acceptance_'.bin2hex(random_bytes(6));
        $this->connection->getPdo()->exec($grammar->compileSavepoint($savepoint));

        try {
            return $acceptance();
        } catch (Throwable $exception) {
            try {
                $this->connection->getPdo()->exec($grammar->compileSavepointRollBack($savepoint));
            } catch (Throwable) {
                // The transaction is already gone (a deadlock rolled it back).
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function wait(string $runId, string $name, ?string $reason = null, ?int $timeoutSeconds = null, array $metadata = []): void
    {
        $run = $this->runs->requireRun($runId);
        $metadata = $this->payloads->payload($metadata);
        $this->durableRuns->createWait($runId, $name, $reason, $timeoutSeconds, $metadata);

        $context = $this->runs->loadContext($runId);
        $this->historyStore->syncDurableState($runId, 'waiting', $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false);

        $this->events->dispatch(new SwarmWaiting(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            waitName: $name,
            reason: $reason,
            metadata: $this->payloads->eventMetadata($context),
            executionMode: $this->runs->publicLifecycleExecutionMode($run),
        ));
        $this->audit->emit('wait.created', [
            'run_id' => $runId,
            'swarm_class' => $run['swarm_class'],
            'topology' => $run['topology'],
            'execution_mode' => $this->runs->publicLifecycleExecutionMode($run),
            'wait_name' => $name,
            'reason' => $reason,
            'timeout_seconds' => $timeoutSeconds,
            'status' => 'waiting',
        ]);
    }
}
