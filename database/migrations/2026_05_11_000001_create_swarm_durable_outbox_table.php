<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox for durable swarm job dispatches.
 *
 * Every dispatch that must be atomic with a preceding DB state change is written
 * here first — inside the same transaction as the state change — and then drained
 * by the swarm:relay command.  This guarantees that a process crash between the DB
 * commit and the queue enqueue cannot leave a run stranded with no job to advance it.
 *
 * Drain flow (swarm:relay):
 *   1. Claim rows atomically via FOR UPDATE SKIP LOCKED, setting reserved_at.
 *   2. Dispatch the corresponding queue job for each claimed row.
 *   3. Delete the row on successful dispatch.
 *
 * Stale reservations (relay worker died between claim and dispatch) become eligible
 * again once reserved_at ages past the configured reservation timeout (default 60 s).
 *
 * Rows are cascade-deleted when the parent run is pruned; no separate cleanup needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_durable_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->string('dispatch_type');
            $table->json('payload');
            $table->string('queue_connection')->nullable();
            $table->string('queue_name')->nullable();
            $table->timestamp('available_at');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('created_at');

            $table->foreign('run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->cascadeOnDelete();

            $table->index(['dispatch_type', 'available_at', 'reserved_at'], 'swarm_outbox_drain_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_durable_outbox');
    }
};
