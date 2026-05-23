<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a dedicated, nullable `run_id` column to `swarm_memories` with a
 * foreign-key cascade to `swarm_run_histories.run_id`.
 *
 * Rationale: the original `swarm_memories` schema deliberately omitted any FK
 * because `scope_id` is polymorphic across four scopes — Run, Conversation,
 * Agent, Swarm — and only the Run-scoped subset is a referential candidate.
 * That left a gap for regulated workloads: when a `swarm_run_histories` row is
 * deleted (e.g. "delete me and my data" GDPR/CCPA request), Run-scoped memory
 * rows persisted until the v0.10 `swarm:memory:purge` retention command swept
 * them up. The window between history delete and purge run is non-zero and
 * operationally awkward for compliance.
 *
 * Fix: keep `scope_id` polymorphic (it still holds the conversation id, agent
 * class, or swarm class for the non-Run scopes), but add a dedicated, nullable
 * `run_id` column that ONLY Run-scoped rows populate. The FK on that column
 * cascades on delete so DB-level guarantees match the application-level
 * intent: when a run history dies, its Run-scoped memories die with it.
 *
 * Mirrors the cascade pattern established by
 * `2026_05_04_000001_add_run_id_foreign_keys_to_swarm_tables.php` for the
 * history family (contexts, artifacts, run_steps, stream_events).
 *
 * Non-Run scopes leave `run_id` NULL; the FK does not constrain them.
 *
 * Applications publishing migrations under custom table names must mirror the
 * equivalent column + FK in their published copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_memories', function (Blueprint $table): void {
            $table->string('run_id')->nullable()->after('scope_id');

            $table->index('run_id', 'swarm_memories_run_id_index');

            $table->foreign('run_id')
                ->references('run_id')->on('swarm_run_histories')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('swarm_memories', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
            $table->dropIndex('swarm_memories_run_id_index');
            $table->dropColumn('run_id');
        });
    }
};
