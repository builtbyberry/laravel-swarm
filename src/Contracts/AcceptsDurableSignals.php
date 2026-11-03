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
     * transaction. Called inside the acceptance transaction.
     *
     * Closes the wait with the UPDATE that takes its row lock, then locks the run row,
     * then records the signal (recordSignal()) and links it to the wait: the order every
     * wait release takes, with no ordinary read before the run row is locked. It closes
     * the wait itself and does not call releaseWaitWithSignal().
     *
     * Returns null when the run has no single open wait of that name: nothing is
     * written, and the caller records the signal with recordSignal(). Otherwise returns
     * what recordSignal() returned — the signal as recorded, with `duplicate` — plus
     * `released`: true when the wait was closed with it; false for a duplicate
     * idempotency key, which closes nothing and writes nothing.
     *
     * @return array<string, mixed>|null
     */
    public function acceptSignal(string $runId, string $name, mixed $payload, ?string $idempotencyKey): ?array;
}
