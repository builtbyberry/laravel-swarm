<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema for the terminal workflow callback delivery store, backing the queue/durable
 * then()/catch() conveniences. The delivery lifecycle (register → settle → claim →
 * deliver) is owned by {@see CallbackDeliveryOutbox}
 * and its database implementation, not restated here.
 *
 * Columns of note:
 * - run_id is INDEXED WITHOUT A FOREIGN KEY, deliberately. A callback is registered at
 *   ->then()/->catch() call time, before the run's swarm_run_histories row is guaranteed
 *   written (a queued run writes history when its job runs), so an FK to swarm_run_histories
 *   would break registration; and a plain single-job queue() run has no swarm_durable_runs
 *   row, so an FK there could not cover every mode. Orphan rows are collected by swarm:prune
 *   against the run's terminal history instead of via a cascade.
 * - callback, context, and last_error are sealed at rest by SwarmPersistenceCipher.
 * - the (status, reserved_at) index serves the drain claim query.
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
            // 'registered'): swarm class, topology, and, for a catch, the settled
            // (redacted) exception class/message. Built from the terminal lifecycle
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
