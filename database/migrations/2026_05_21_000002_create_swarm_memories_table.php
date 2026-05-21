<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live memory entries for the database-backed SwarmMemory driver.
 *
 * One row per entry, addressed by the natural key `(scope, scope_id, key)`.
 * `value` and `metadata` are JSON columns to support arbitrary plain-data
 * shapes. Timestamps drive retention purges and propagation freshness
 * heuristics layered on later.
 *
 * Indexing strategy mirrors the three hot paths the driver and policy layer
 * exercise against this table:
 *
 *   - `(scope, scope_id)`  — propagation reads on agent invocation.
 *   - `(scope, key)`       — value lookups across scope_ids (e.g. "every
 *                            Run-scoped `last_output` written in the last hour").
 *   - `created_at`         — retention purges via the v0.10 `swarm:memory:purge`
 *                            command (#124).
 *
 * The composite `(scope, scope_id, key)` unique index also enforces one-row
 * per-address semantics that {@see DatabaseMemoryStore::put()} relies on for
 * its `upsert(..., ['scope', 'scope_id', 'key'], ...)` call.
 *
 * Foreign-key design decision: no FK.
 *
 * `scope_id` is polymorphic across four scopes — for `MemoryScope::Run` it
 * holds a `swarm_run_histories.run_id`, for `Conversation` an opaque
 * conversation id, for `Agent` a fully-qualified agent class string, and for
 * `Swarm` a fully-qualified swarm class string. Only the Run-scoped subset is
 * a referential candidate; the rest are not even rows in any swarm table.
 * Conditional foreign keys aren't portable across MySQL/Postgres/SQLite, and
 * splitting the table by scope to enable a per-scope FK fragments propagation
 * reads (the hottest path).
 *
 * Trade-off accepted: Run-scoped entries are not cascade-deleted when their
 * parent `swarm_run_histories` row is purged. The v0.10 `swarm:memory:purge`
 * command (#124) reaps them via `created_at` retention, and the same window
 * applies uniformly to all scopes. Operators who need stricter coupling can
 * add an FK in an application migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_memories', function (Blueprint $table): void {
            $table->id();
            $table->string('scope');
            $table->string('scope_id');
            $table->string('key');
            $table->json('value');
            $table->json('metadata');
            $table->timestamps();

            // One row per address. Also serves the (scope, scope_id, key)
            // index hit for point reads.
            $table->unique(['scope', 'scope_id', 'key']);

            // Propagation reads on agent invocation: "every entry in this
            // (scope, scope_id) namespace".
            $table->index(['scope', 'scope_id'], 'swarm_memories_scope_scope_id_index');

            // Value lookups across scope_ids: "every `last_output` written
            // under Run scope".
            $table->index(['scope', 'key'], 'swarm_memories_scope_key_index');

            // Retention purges by age.
            $table->index('created_at', 'swarm_memories_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_memories');
    }
};
