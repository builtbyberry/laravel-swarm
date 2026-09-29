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
 *   drain()     → claim 'pending' rows and hand each to a DeliverSwarmCallback job
 *   deliver()   → the job unseals + verifies + invokes, then deletes on success or
 *                 increments attempts / dead-letters on failure
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
     *                        unavailable (fail-closed under a non-database driver) so a missing
     *                        guarantee is never silently swallowed at registration.
     */
    public function register(string $runId, CallbackSlot $slot, Closure $callback): void;

    /**
     * Settle a run's callbacks for its terminal outcome. Flips the matching-outcome
     * rows from 'registered' to 'pending' (recording the terminal context for
     * delivery) and DELETES the other outcome's rows. MUST be called inside the same
     * DB transaction as the terminal state write so the flip is atomic with it.
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
     * closure never leaves the table — only the row id travels to the job.
     */
    public function drain(int $limit = 100): CallbackDrainResult;

    /**
     * Deliver a single claimed row (invoked by DeliverSwarmCallback): unseal and
     * verify the closure signature, invoke it with the row's terminal context, then
     * delete the row on success. On failure, increment attempts and release the
     * reservation; after swarm.callbacks.max_attempts move the row to 'dead_letter'.
     * Idempotent: a row that is missing or no longer deliverable is a no-op.
     */
    public function deliver(int $id): void;

    /**
     * Whether the outbox is backed by a working store. Returns false under a
     * non-database persistence driver and when the swarm_callback_deliveries table
     * is missing.
     */
    public function isAvailable(): bool;

    public function assertReady(): void;
}
