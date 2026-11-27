<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

/**
 * A store refused to move a run because the transaction it ran in could not prove it
 * sees every wait created on that run: a wait created since may still be open. Nothing
 * the refused call wrote persists. It is retryable, but only from a fresh transaction:
 * a savepoint inside the same transaction keeps the same view of the waits.
 *
 * Thrown by DurableRunStore::resume() and by the wait closers (see
 * \BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore and
 * \BuiltByBerry\LaravelSwarm\Contracts\AcceptsDurableSignals). How each caller
 * surfaces it is that caller's: DurableLifecycleController for resume,
 * DurableSignalHandler for signals.
 */
class DurableWaitSetChangedException extends SwarmException {}
