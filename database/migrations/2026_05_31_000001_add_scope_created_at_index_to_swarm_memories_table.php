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
 *
 * Large-table caveat: this builds the index inline, which takes a write lock
 * (MySQL/InnoDB) or an exclusive lock (Postgres) for the duration of the
 * build — on the very large `swarm_memories` tables this index targets, that
 * can stall `php artisan migrate` and block writes for minutes. Operators with
 * a large table should build it out-of-band (Postgres `CREATE INDEX
 * CONCURRENTLY`, MySQL online DDL / `pt-online-schema-change`) and then mark
 * this migration as run, rather than letting it lock the table during deploy.
 * See UPGRADING.md (v0.10.0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_memories', function (Blueprint $table): void {
            // Inline build — takes a table lock for the build duration. On a
            // large table, prefer an out-of-band concurrent/online index build
            // (see the class docblock and UPGRADING.md).
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
