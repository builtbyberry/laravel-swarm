<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a composite `(scope, created_at)` index to `swarm_memories`.
 *
 * Rationale: the `swarm:memory:purge` retention command (v0.10) sweeps with
 * `WHERE scope = ? AND created_at < ?`, one scope at a time, and the snapshot
 * cascade subquery filters `scope = 'run' AND run_id IS NOT NULL AND
 * created_at < ?`. The shipped indexes don't serve that shape: the single
 * `created_at` index ignores `scope`, and the `(scope, scope_id)` / `(scope,
 * key)` composites lead with `scope` but then a column the purge doesn't
 * constrain, leaving `created_at` as a residual filter. On the large memory
 * tables this command exists for (enterprise retention enforcement), a
 * dedicated `(scope, created_at)` index lets the planner seek the scope
 * partition and range-scan `created_at` directly.
 *
 * Added as a standalone migration because the table's create migration
 * (`2026_05_21_000002_create_swarm_memories_table.php`) already shipped in
 * v0.9.0 — consumers have run it, so it cannot be edited in place.
 *
 * Applications publishing migrations under custom table names must add the
 * equivalent index to their published copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_memories', function (Blueprint $table): void {
            $table->index(['scope', 'created_at'], 'swarm_memories_scope_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('swarm_memories', function (Blueprint $table): void {
            $table->dropIndex('swarm_memories_scope_created_at_index');
        });
    }
};
