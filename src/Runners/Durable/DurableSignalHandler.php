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
use BuiltByBerry\LaravelSwarm\Support\ApplicationTransactionBoundary;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal
 */
class DurableSignalHandler
{
    use SafeReporting;

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
        protected LoggerInterface $logger,
    ) {}

    public function signal(string $runId, string $name, mixed $payload = null, ?string $idempotencyKey = null): DurableSignalResult
    {
        $run = $this->runs->requireRun($runId);
        $capturedPayload = $this->payloads->payload($payload);

        [$signal, $accepted] = $this->durableRuns instanceof AcceptsDurableSignals
            ? $this->acceptAtomically($this->durableRuns, $run, $runId, $name, $capturedPayload, $idempotencyKey)
            : $this->recordThenAccept($run, $runId, $name, $capturedPayload, $idempotencyKey);

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
     * A store that records the signal in its acceptance transaction: an acceptance that
     * aborts leaves no signal behind, so the same idempotency key is accepted when it is
     * sent again.
     *
     * A paused run's open wait is accepted too, but only outside an application
     * transaction (the swarm connection's transaction level is 0 when signal() is called).
     * The wait closes with the signal and nothing is continued from here: the run is
     * left to resume (see DurableLifecycleController::resume()). Inside an application
     * transaction the signal is recorded and not accepted, as it was
     * before paused runs were accepted: there, an acceptance would publish the context
     * from the application's older snapshot and keep the run row locked until the
     * application commits, which a later resume() in the same transaction then deadlocks
     * against. Laravel's testing transaction wrapper counts as an application transaction.
     *
     * @param  array<string, mixed>  $run
     * @return array{0: array<string, mixed>, 1: bool}
     */
    protected function acceptAtomically(AcceptsDurableSignals $store, array $run, string $runId, string $name, mixed $capturedPayload, ?string $idempotencyKey): array
    {
        if ($run['status'] === 'waiting' || ($run['status'] === 'paused' && $this->connection->transactionLevel() === 0)) {
            // A key already recorded is answered without taking a lock — a webhook's
            // retries would otherwise each lock the wait and run rows — but only outside an
            // application transaction: inside one this read would fix MySQL's snapshot
            // before the acceptance locks the run row. The acceptance's own check, under
            // the run lock, stays the one that decides.
            if ($idempotencyKey !== null && $this->connection->transactionLevel() === 0) {
                $existing = $store->signalForIdempotencyKey($runId, $idempotencyKey);

                if ($existing !== null) {
                    return [array_merge($existing, ['duplicate' => true]), false];
                }
            }

            $accepted = ApplicationTransactionBoundary::run(
                $this->connection,
                fn (): ?array => $this->connection->transaction(function () use ($store, $runId, $name, $capturedPayload, $idempotencyKey): ?array {
                    $signal = $store->acceptSignal($runId, $name, $capturedPayload, $idempotencyKey);

                    if ($signal !== null && $signal['released']) {
                        $this->publishAcceptance($runId, $name, $signal['id'], $capturedPayload);
                    }

                    return $signal;
                }),
                $this->reportRollbackFailure($runId, $name),
            );

            if ($accepted !== null) {
                $released = $accepted['released'];
                unset($accepted['released']);

                return [$accepted, $released];
            }
        }

        // No open wait of this name, a run that is neither waiting nor paused, or a paused
        // run inside an application transaction: recorded, not accepted.
        $signal = $this->durableRuns->recordSignal($runId, $name, $capturedPayload, $idempotencyKey);

        if ($run['status'] === 'paused' && $this->connection->transactionLevel() > 0 && ($signal['duplicate'] ?? false) !== true) {
            // Nothing in the result says why a paused run's signal was not tried for
            // acceptance; this does. Whether a wait of that name is open is not checked.
            $this->safeLog($this->logger, 'notice', 'laravel-swarm: a signal for a paused durable run was sent inside a database transaction, so it was recorded without being accepted. If the run has an open wait of that name, send the signal again after the run is resumed, with a new idempotency key.', [
                'run_id' => $runId,
                'signal_name' => $name,
            ]);
        }

        return [$signal, false];
    }

    /**
     * A store without atomic acceptance: the signal is recorded first and accepted in its
     * own transaction, so an acceptance that aborts leaves the recorded signal behind and
     * the same idempotency key is then a duplicate (see AcceptsDurableSignals). Only a
     * waiting run's wait is accepted; a paused run's signal is recorded.
     *
     * @param  array<string, mixed>  $run
     * @return array{0: array<string, mixed>, 1: bool}
     */
    protected function recordThenAccept(array $run, string $runId, string $name, mixed $capturedPayload, ?string $idempotencyKey): array
    {
        $signal = $this->durableRuns->recordSignal($runId, $name, $capturedPayload, $idempotencyKey);

        if (($signal['duplicate'] ?? false) === true || $run['status'] !== 'waiting') {
            return [$signal, false];
        }

        $accepted = ApplicationTransactionBoundary::run(
            $this->connection,
            fn (): bool => $this->connection->transaction(function () use ($runId, $name, $signal, $capturedPayload): bool {
                if (! $this->durableRuns->releaseWaitWithSignal($runId, $name, (int) $signal['id'])) {
                    return false;
                }

                $this->publishAcceptance($runId, $name, (int) $signal['id'], $capturedPayload);

                return true;
            }),
            $this->reportRollbackFailure($runId, $name),
        );

        return [$signal, $accepted];
    }

    /**
     * @return Closure(Throwable): void
     */
    protected function reportRollbackFailure(string $runId, string $name): Closure
    {
        return function (Throwable $rollbackFailure) use ($runId, $name): void {
            $this->safeLog($this->logger, 'warning', 'laravel-swarm: could not roll a failed durable signal acceptance back to its savepoint inside the application transaction; do not commit that transaction.', [
                'run_id' => $runId,
                'signal_name' => $name,
                'exception' => $rollbackFailure::class,
                'message' => $rollbackFailure->getMessage(),
            ]);
        };
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

        // A paused run is continued by resume(), not from here.
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
