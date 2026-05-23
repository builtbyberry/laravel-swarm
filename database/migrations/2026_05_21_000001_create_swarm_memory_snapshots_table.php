<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frozen memory views captured at agent invocation, for compliance-grade replay.
 *
 * Each row holds one snapshot tied to a single swarm_run_steps row (identified
 * by the (run_id, step_index) natural key shared with swarm_run_steps). The
 * snapshot mechanism (#111) writes the frozen agent-visible memory view into
 * `payload` and any tool-call input/output pairs into `tool_calls` at the
 * moment of invocation. The durable replay path (#112) reads from these rows
 * instead of querying live SwarmMemory, guaranteeing the resumed agent sees
 * byte-identical inputs to its original invocation.
 *
 * Foreign key: cascades from swarm_run_histories.run_id, mirroring the existing
 * history-family pattern (see 2026_05_04_000001_add_run_id_foreign_keys_to_swarm_tables).
 * The natural-key tie to swarm_run_steps is enforced by the composite
 * unique(run_id, step_index) constraint on both tables — they index the same
 * tuple, so snapshot lookup by (run_id, step_index) is an index hit on both
 * sides without a literal composite FK (which SQLite supports unevenly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_memory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id');
            $table->unsignedInteger('step_index');
            $table->json('payload');
            $table->json('tool_calls');
            $table->timestamps();

            // One snapshot per step. Composite unique also indexes (run_id, step_index)
            // for fast replay lookup — issue #110 AC: "Index for fast replay lookup
            // by (run_id, step_index)".
            $table->unique(['run_id', 'step_index']);

            $table->foreign('run_id')
                ->references('run_id')->on('swarm_run_histories')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_memory_snapshots');
    }
};
