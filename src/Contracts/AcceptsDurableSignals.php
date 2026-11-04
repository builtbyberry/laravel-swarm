<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Optional atomic signal acceptance for durable stores.
 *
 * A store with this capability records a signal in the same transaction that closes its
 * wait, so an acceptance that aborts leaves no signal behind and the same idempotency key
 * is accepted when it is sent again. A store without it keeps the weaker boundary of
 * recordSignal() followed by releaseWaitWithSignal(): the signal row is written before the
 * acceptance, survives an abort, and the key is then a duplicate. A decorating store keeps
 * the capability only if it implements this interface and forwards it.
 */
interface AcceptsDurableSignals
{
    /**
     * Close the named open wait with a new signal, and record the signal, in one
     * transaction. Called inside the acceptance transaction, and must write on the same
     * database connection as it, which also publishes the outcome to the context and
     * history: the atomicity holds only because one transaction covers all of it.
     *
     * Closes the wait with the UPDATE that takes its row lock, then locks the run row,
     * then records the signal (recordSignal()) and links it to the wait: the order every
     * wait release takes, with no ordinary read before the run row is locked. It closes
     * the wait itself and does not call releaseWaitWithSignal().
     *
     * It is called for a waiting run and for a paused one. It closes the open wait
     * whatever the run's status, and must not move a paused run out of `paused`: the
     * signal is accepted, and the run continues only when it is resumed.
     *
     * Returns null when the run has no single open wait of that name: nothing is
     * written, and the caller records the signal with recordSignal(). Otherwise returns
     * what recordSignal() returned — the signal as recorded, with `duplicate` — plus
     * `released`: true when the wait was closed with it; false for a duplicate
     * idempotency key, which closes nothing and writes nothing.
     *
     * @return array{id: int, duplicate: bool, released: bool, ...<string, mixed>}|null
     */
    public function acceptSignal(string $runId, string $name, mixed $payload, ?string $idempotencyKey): ?array;

    /**
     * The run's signal recorded under this idempotency key, as recordSignal() maps it, or
     * null. A plain read with no lock: callers use it to answer a repeated key without
     * locking anything, outside any transaction, and leave the decision to acceptSignal().
     *
     * @return array<string, mixed>|null
     */
    public function signalForIdempotencyKey(string $runId, string $idempotencyKey): ?array;
}
