<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes the columns durable per-node streaming needs onto the causal log (#298).
 *
 * When a durable run streams its node executions into the causal log, a node that
 * crashes and re-executes on resume must have its prior attempt's events retracted
 * before the fresh attempt is written — otherwise a fold would show two attempts.
 * The retraction is a `node_reexecuted` void-edge keyed by the node and the attempt
 * it voids, so resume must locate "this node's prior-attempt events" by a
 * metadata-only query (never decrypting payload). That needs two values promoted
 * out of the JSON payload into queryable, indexed columns, mirroring the #282
 * void-edge column promotion:
 *
 * - `node_id` — the run-structure node an event belongs to (mirrors the `node_id`
 *   carried inside the JSON payload, #284) promoted so the resume-time void query
 *   can select a node's events without unpacking JSON across drivers. "Whose node."
 * - `attempt_epoch` — a durable-owned per-node attempt counter (derived from the
 *   run's recovery/retry bookkeeping) stamped on each event a durable advancer
 *   writes. The vendor `invocation_id` is nullable and cannot be the rollback key,
 *   so the epoch is the authoritative attempt discriminator: resume voids the prior
 *   epoch's events for the node, then writes the new epoch. "Which attempt."
 *
 * Both columns are nullable so existing rows and every non-durable-streamed event
 * read back unchanged (a null `attempt_epoch` is a non-durable-streamed event,
 * outside the rollback path). The migration is reversible via dropColumn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_stream_events', function (Blueprint $table): void {
            $table->string('node_id')->nullable()->after('event_uuid');
            $table->unsignedInteger('attempt_epoch')->nullable()->after('node_id');

            // Resume-time rollback lookup: "this node's prior-attempt events",
            // scoped per run. Metadata-only — never touches the JSON payload.
            $table->index(
                ['run_id', 'node_id', 'attempt_epoch'],
                'swarm_stream_events_run_node_epoch_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('swarm_stream_events', function (Blueprint $table): void {
            $table->dropIndex('swarm_stream_events_run_node_epoch_index');
            $table->dropColumn(['node_id', 'attempt_epoch']);
        });
    }
};
