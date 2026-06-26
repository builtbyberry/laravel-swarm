<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unique index on swarm_cold_archives (run_id, archive_type, sequence) (#287 OG3).
 *
 * Prerequisite for DatabaseColdArchiveDriver::graduate() switching from
 * DELETE+INSERT to insertOrIgnore. Without this index, insertOrIgnore has no
 * conflict to detect and would silently write duplicate event rows.
 *
 * For event rows (archive_type='event'), sequence is the hot-table id and is
 * always non-null — the unique constraint prevents duplicate cold event rows
 * across concurrent graduation attempts.
 *
 * For snapshot rows (archive_type='snapshot'), sequence is null. Most databases
 * treat NULL != NULL in unique indexes, so multiple nulls are technically allowed
 * by the constraint — but the compactor guarantees exactly one snapshot per run
 * via an exists() check, so duplicate snapshots cannot arise in practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_cold_archives', function (Blueprint $table): void {
            $table->unique(
                ['run_id', 'archive_type', 'sequence'],
                'swarm_cold_archives_run_id_type_sequence_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('swarm_cold_archives', function (Blueprint $table): void {
            $table->dropUnique('swarm_cold_archives_run_id_type_sequence_unique');
        });
    }
};
