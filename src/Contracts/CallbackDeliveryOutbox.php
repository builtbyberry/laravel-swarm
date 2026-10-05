<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Enums\CallbackSlot;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\CallbackDrainResult;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmTerminalContext;
use Closure;

/**
 * Persisted delivery buffer for terminal workflow `then()` / `catch()` callbacks
 * registered on a {@see QueuedSwarmResponse}
 * or {@see DurableSwarmResponse}.
 *
 * Lifecycle of a row:
 *   register()  → status 'registered' (a signed, sealed SerializableClosure)
 *   settle()    → matching-outcome rows 'registered' → 'pending' (in the terminal
 *                 transaction); the other outcome's rows are deleted
 *   drain()     → lease eligible rows with a claim token and dispatch a delivery job
 *   deliver()   → atomically acquire pending → delivering, increment attempts, then
 *                 unseal + verify + invoke; delete on success or retry/dead-letter
 *
 * Delivery is at-least-once, never exactly-once. Callbacks MUST be idempotent.
 *
 * Consumers bind this contract, never the `@internal` concrete outbox or the
 * `@internal` {@see SwarmPersistenceCipher}.
 * The default binding resolves the database-backed outbox when the persistence
 * driver supports it, and a no-op (fail-closed at register/settle) otherwise —
 * mirroring how {@see AuditOutbox} is bound.
 */
interface CallbackDeliveryOutbox
{
    /**
     * Persist a terminal callback for a run as a signed, sealed SerializableClosure
     * with status 'registered'. Called from the fluent then()/catch() surface at
     * registration time, before the run can settle.
     *
     * @throws SwarmException when the outbox is
     *                        unavailable (fail-closed under a non-database driver), or the process
     *                        has no APP_KEY signing key (the callback would be stored unsigned and
     *                        could never be delivered), so a missing guarantee is never silently
     *                        swallowed at registration.
     */
    public function register(string $runId, CallbackSlot $slot, Closure $callback): void;

    /**
     * Whether the run has any callback rows at all. A cheap run_id-indexed existence
     * check the terminal seam uses to skip settle()/discard() entirely for the common
     * case of a run that registered no callbacks — avoiding a no-match DELETE/UPDATE
     * (and its gap locks) inside the hot terminal transaction.
     */
    public function hasFor(string $runId): bool;

    /**
     * Settle a run's callbacks for its terminal outcome. Flips the matching-outcome
     * rows from 'registered' to 'pending' (recording the terminal context for
     * delivery) and deletes only still-registered rows for the other outcome. MUST be
     * called inside the same DB transaction as the terminal state write so the flip is
     * atomic with it.
     *
     * A no-op when the outbox is unavailable or the run registered no callbacks.
     */
    public function settle(string $runId, SwarmTerminalContext $context): void;

    /**
     * Drop every callback registered for a run without delivering any. Called when a
     * run reaches a terminal state that is neither completion nor failure
     * (cancellation), where neither then nor catch fires.
     */
    public function discard(string $runId): void;

    /**
     * Claim pending rows and hand each to a DeliverSwarmCallback job. The sealed
     * closure never leaves the table — only the row id and opaque claim token travel.
     */
    public function drain(int $limit = 100): CallbackDrainResult;

    /**
     * Deliver a single claimed row (invoked by DeliverSwarmCallback). Only the job
     * holding the current token may atomically acquire pending → delivering and count
     * an attempt. Duplicate and obsolete jobs are no-ops.
     */
    public function deliver(int $id, string $claimToken): void;

    /**
     * Whether the outbox is backed by a working store. Returns false under a
     * non-database persistence driver, when the configured callback table is missing,
     * or when its required claim-token/backoff columns are absent.
     */
    public function isAvailable(): bool;

    public function assertReady(): void;
}
