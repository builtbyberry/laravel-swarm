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
     * Record a signal and close the named open wait with it. Called inside the acceptance
     * transaction; writes nothing of its own outside it.
     *
     * Takes the wait row, then the run row, before it records the signal — the order
     * every wait release takes — and makes no ordinary read before the run row is
     * locked.
     *
     * Returns null when the run has no open wait of that name: nothing is written, and the
     * caller records the signal with recordSignal(). Otherwise returns what recordSignal()
     * returned — the signal as recorded, with `duplicate` — plus `released`: whether
     * releaseWaitWithSignal() closed the wait. A duplicate idempotency key releases
     * nothing and writes nothing.
     *
     * @return array<string, mixed>|null
     */
    public function acceptSignal(string $runId, string $name, mixed $payload, ?string $idempotencyKey): ?array;
}
