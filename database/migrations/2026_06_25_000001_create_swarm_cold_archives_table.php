<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cold archive table for the hot/cold tiering substrate (#286).
 *
 * A single `swarm_cold_archives` table stores both archive surfaces for a
 * graduated run:
 *
 * - Event rows (archive_type='event') — the raw, unsealed JSON event payloads
 *   in the same shape as `swarm_stream_events.payload`. These are the audit
 *   surface: every event that was compacted out of hot storage is retained here
 *   addressable by (run_id, sequence) so an audit fold can reconstruct the full
 *   history without touching the hot table.
 *
 * - Snapshot row (archive_type='snapshot') — the sealed fold-snapshot string
 *   for operational resume. Callers decrypt via openStrict() (#212 convention);
 *   a wrong/rotated APP_KEY throws a re-dispatchable SwarmException. Also carries
 *   the `base_pointer` — the hot/cold boundary: events with hot-table id < base
 *   live here; id >= base live in the hot store. The pointer is written atomically
 *   alongside the snapshot by the #287 compactor's CAS swap so it is always
 *   consistent with the archived event set.
 *
 * The (run_id, archive_type) unique index enforces one snapshot row per run;
 * event rows share a run_id but differ by sequence. Both are addressed by
 * the run_id index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_cold_archives', function (Blueprint $table): void {
            $table->id();
            // No FK to swarm_run_histories: cold archives are long-term audit retention
            // and may intentionally outlive the run history row.
            $table->string('run_id');
            // 'snapshot' | 'event'
            $table->string('archive_type');
            // For event rows: the hot-table id of this event (the sequence boundary).
            // For snapshot rows: null (the sequence range is captured by base_pointer).
            $table->unsignedBigInteger('sequence')->nullable();
            // For event rows: raw JSON payload (unsealed, same shape as swarm_stream_events.payload).
            // For snapshot rows: the sealed fold-snapshot string (sw0:-prefixed when encrypt_at_rest is on).
            $table->longText('payload');
            // Set only on the snapshot row for this run. Null on all event rows.
            // The hot/cold boundary: hot-table id < base_pointer → cold; id >= base_pointer → hot.
            $table->unsignedBigInteger('base_pointer')->nullable();
            $table->timestamps();

            $table->index('run_id');
            // Fast snapshot lookup (one snapshot row per run by convention enforced by
            // the #287 compactor's upsert). We do not add a unique constraint here
            // because event rows also carry archive_type='event', so a table-level
            // unique on (run_id, archive_type) would reject all but the first event row.
            $table->index(['run_id', 'archive_type'], 'swarm_cold_archives_run_id_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_cold_archives');
    }
};
