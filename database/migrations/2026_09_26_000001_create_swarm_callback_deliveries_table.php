<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional store for terminal workflow callback deliveries.
 *
 * Backs the queue/durable `then()` / `catch()` conveniences. A callback
 * registered on a QueuedSwarmResponse or DurableSwarmResponse is persisted here
 * as a signed, sealed SerializableClosure with status 'registered'. When the run
 * settles, the terminal seam flips the matching-outcome rows to 'pending' inside
 * the SAME transaction as the terminal state write, so a crash between the
 * terminal commit and the callback dispatch cannot silently drop the callback.
 *
 * Delivery (swarm:relay --type=callback):
 *   1. Claim pending rows atomically via FOR UPDATE SKIP LOCKED, setting reserved_at.
 *   2. Dispatch a DeliverSwarmCallback job carrying only the row id (never the
 *      sealed closure, which stays at rest in this table).
 *   3. The job unseals + verifies the closure signature, invokes it, and deletes
 *      the row on success. On failure it increments attempts and releases the
 *      reservation; after swarm.callbacks.max_attempts the row moves to
 *      'dead_letter' and stops being re-claimed.
 *
 * Delivery is at-least-once, never exactly-once: a crash after the closure runs
 * but before the row delete re-delivers on the next drain. Callbacks must be
 * idempotent.
 *
 * Unlike the durable outbox, callback rows have NO parent-run cascade — a plain
 * single-job queue() run has only a swarm_run_histories row (no durable row),
 * so this table is deliberately run_id-indexed without a foreign key so it can
 * hold callbacks for every terminal mode. Retention is prune-based
 * (swarm.callbacks.retention_days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_callback_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->string('slot');
            $table->longText('callback');
            // Sealed terminal-context JSON, populated at the terminal flip (null while
            // 'registered'): swarm class, topology, execution mode, and, for a catch,
            // the settled exception class/message. Built from the terminal lifecycle
            // record so the delivered callback receives an always-available summary
            // without reconstructing the full (possibly uncaptured) SwarmResponse.
            $table->longText('context')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('status')->default('registered');
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->index(['status', 'reserved_at'], 'swarm_callback_deliveries_drain_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_callback_deliveries');
    }
};
