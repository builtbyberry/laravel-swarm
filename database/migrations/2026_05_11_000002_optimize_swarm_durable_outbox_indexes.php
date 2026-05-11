<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the composite swarm_outbox_drain_idx with two targeted indexes:
 *
 *   swarm_outbox_unfiltered_idx  (available_at, id)
 *     Covers the common unfiltered drain: WHERE available_at <= NOW()
 *     AND reserved_at IS NULL ORDER BY id LIMIT n.
 *
 *   swarm_outbox_typed_idx  (dispatch_type, available_at, id)
 *     Covers type-filtered drains: WHERE dispatch_type = ? AND ...
 *
 * On PostgreSQL we additionally create a partial index on available_at that
 * excludes already-reserved rows, keeping the working set small under load.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('swarm_durable_outbox', function (\Illuminate\Database\Schema\Blueprint $table) use ($driver): void {
            $table->dropIndex('swarm_outbox_drain_idx');
            $table->index(['available_at', 'id'], 'swarm_outbox_unfiltered_idx');
            $table->index(['dispatch_type', 'available_at', 'id'], 'swarm_outbox_typed_idx');
        });

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS swarm_outbox_pending_idx
                 ON swarm_durable_outbox (available_at, id)
                 WHERE reserved_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS swarm_outbox_pending_idx');
        }

        Schema::table('swarm_durable_outbox', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropIndex('swarm_outbox_unfiltered_idx');
            $table->dropIndex('swarm_outbox_typed_idx');
            $table->index(['dispatch_type', 'available_at', 'reserved_at'], 'swarm_outbox_drain_idx');
        });
    }
};
