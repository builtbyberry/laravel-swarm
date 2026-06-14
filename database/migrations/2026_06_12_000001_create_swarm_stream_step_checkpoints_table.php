<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-step output checkpoints for idempotent multi-step resume of non-durable
 * streamed runs (issue #202).
 *
 * Each row records the completed output + usage of ONE non-final streamed step,
 * keyed by the `(run_id, step_index)` natural key shared with swarm_run_steps
 * and swarm_memory_snapshots. On resume of an abandoned `stream()` run the
 * runner reads these rows to skip already-completed non-final steps (no provider
 * re-invoke, no tool side-effect re-fire) and rehydrate the next step's prompt
 * byte-identically. It is the non-durable analogue of swarm_durable_node_outputs.
 *
 * `output` is the RAW, untruncated value the step fed into the next step's
 * prompt — byte-identity depends on it — stored as longText and NULLABLE. A
 * null output marks a row that was reserved but whose step crashed before
 * completion; the store treats it as absent so the step re-executes. Only a
 * non-null output means "completed, safe to skip".
 *
 * Foreign key: cascades from swarm_run_histories.run_id, mirroring the
 * snapshot/history-family pattern. The natural-key tie to swarm_run_steps is
 * enforced by the composite unique(run_id, step_index) index here and on the
 * sibling tables, without a literal composite FK (which SQLite supports
 * unevenly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_stream_step_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id');
            $table->unsignedInteger('step_index');
            $table->longText('output')->nullable();
            $table->json('usage');
            $table->timestamps();

            // One checkpoint per step; the composite unique also indexes
            // (run_id, step_index) for fast resume lookup.
            $table->unique(['run_id', 'step_index']);

            $table->foreign('run_id')
                ->references('run_id')->on('swarm_run_histories')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_stream_step_checkpoints');
    }
};
