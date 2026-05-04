<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add database-level referential integrity for all run_id edges.
 *
 * History children (contexts, artifacts, run_steps, stream_events) reference
 * swarm_run_histories with ON DELETE CASCADE.
 *
 * Durable children (branches, node_states, run_state, node_outputs, signals,
 * waits, labels, details, progress) reference swarm_durable_runs with ON DELETE
 * CASCADE.  durable_child_runs.parent_run_id also cascades; child_run_id has no
 * FK because its referenced run lives on a separate retention timeline.
 *
 * Nullable relationships use SET NULL:
 *   - swarm_durable_runs.parent_run_id  (self-referential; pruning a parent must
 *     not block pruning by holding a FK on its own table)
 *   - swarm_durable_waits.signal_id  (wait can outlive the signal record briefly
 *     during batch deletes; wait is also cascade-deleted via run_id)
 *   - swarm_durable_webhook_idempotency.run_id  (reservations exist before a run
 *     is created; must survive independent of run row lifecycle)
 *
 * Applications publishing these migrations under custom table names must mirror
 * equivalent constraints in their published copies.  See docs/maintenance.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- History family ---
        Schema::table('swarm_contexts', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_run_histories')
                ->cascadeOnDelete();
        });

        Schema::table('swarm_artifacts', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_run_histories')
                ->cascadeOnDelete();
        });

        Schema::table('swarm_run_steps', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_run_histories')
                ->cascadeOnDelete();
        });

        Schema::table('swarm_stream_events', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_run_histories')
                ->cascadeOnDelete();
        });

        // --- Durable family ---
        Schema::table('swarm_durable_branches', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->cascadeOnDelete();
        });

        Schema::table('swarm_durable_node_states', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->cascadeOnDelete();
        });

        Schema::table('swarm_durable_run_state', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->cascadeOnDelete();
        });

        Schema::table('swarm_durable_node_outputs', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->cascadeOnDelete();
        });

        Schema::table('swarm_durable_signals', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->cascadeOnDelete();
        });

        Schema::table('swarm_durable_waits', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->cascadeOnDelete();

            $table->foreign('signal_id')
                ->references('id')->on('swarm_durable_signals')
                ->nullOnDelete();
        });

        Schema::table('swarm_durable_labels', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->cascadeOnDelete();
        });

        Schema::table('swarm_durable_details', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->cascadeOnDelete();
        });

        Schema::table('swarm_durable_progress', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->cascadeOnDelete();
        });

        Schema::table('swarm_durable_child_runs', function (Blueprint $table): void {
            $table->foreign('parent_run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->cascadeOnDelete();
            // child_run_id intentionally has no FK: the referenced durable run
            // lives on its own retention timeline and may be pruned independently.
        });

        Schema::table('swarm_durable_webhook_idempotency', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->nullOnDelete();
        });

        // Self-referential: a parent run's prune must not be blocked by child runs.
        Schema::table('swarm_durable_runs', function (Blueprint $table): void {
            $table->foreign('parent_run_id')
                ->references('run_id')->on('swarm_durable_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('swarm_durable_runs', function (Blueprint $table): void {
            $table->dropForeign(['parent_run_id']);
        });

        Schema::table('swarm_durable_webhook_idempotency', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_durable_child_runs', function (Blueprint $table): void {
            $table->dropForeign(['parent_run_id']);
        });

        Schema::table('swarm_durable_progress', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_durable_details', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_durable_labels', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_durable_waits', function (Blueprint $table): void {
            $table->dropForeign(['signal_id']);
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_durable_signals', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_durable_node_outputs', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_durable_run_state', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_durable_node_states', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_durable_branches', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_stream_events', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_run_steps', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_artifacts', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::table('swarm_contexts', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });
    }
};
