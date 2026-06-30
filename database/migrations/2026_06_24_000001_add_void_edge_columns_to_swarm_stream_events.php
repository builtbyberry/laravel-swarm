<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes swarm_stream_events into the append-only causal log substrate (#282).
 *
 * The table already stores typed stream events in DB-sequenced (`id`) causal
 * order. This migration adds the columns the causal log needs:
 *
 * - `event_uuid` — the event's OWN string UUID (mirrors the `id` carried inside
 *   the JSON payload) promoted to a queryable, indexed column so a void-edge can
 *   locate the event it targets without unpacking JSON. "Who am I."
 * - `void_type` / `void_target_event_uuid` / `void_reason` — populated only on
 *   void-edge rows. A void-edge is itself an appended event that points at the
 *   `event_uuid` of the event it supersedes / replaces / abandons, with a reason.
 *   The voided event stays in the log; nothing is deleted. "Who do I void."
 * - `sealed_at` — set later by the #287 background compactor when an event passes
 *   out of the unsealed window. Null here means unsealed; `appendVoidEdge()`
 *   refuses to void a sealed event. No code populates it yet — the guard ships
 *   first so the invariant holds from day one.
 *
 * All columns are nullable so existing rows (and every non-void event) read back
 * unchanged. The migration is reversible via dropColumn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_stream_events', function (Blueprint $table): void {
            $table->string('event_uuid')->nullable()->after('event_type');
            $table->string('void_type')->nullable()->after('payload');
            $table->string('void_target_event_uuid')->nullable()->after('void_type');
            $table->text('void_reason')->nullable()->after('void_target_event_uuid');
            $table->timestamp('sealed_at')->nullable()->after('void_reason');

            // Target lookup by UUID (isSealed, fold-layer joins) and void-edge
            // filtering (read-policy fold, #283), both scoped per run.
            $table->index(['run_id', 'event_uuid'], 'swarm_stream_events_run_id_event_uuid_index');
            $table->index(['run_id', 'void_type'], 'swarm_stream_events_run_id_void_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('swarm_stream_events', function (Blueprint $table): void {
            $table->dropIndex('swarm_stream_events_run_id_event_uuid_index');
            $table->dropIndex('swarm_stream_events_run_id_void_type_index');
            $table->dropColumn([
                'event_uuid',
                'void_type',
                'void_target_event_uuid',
                'void_reason',
                'sealed_at',
            ]);
        });
    }
};
