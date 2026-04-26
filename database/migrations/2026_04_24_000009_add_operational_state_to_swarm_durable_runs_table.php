<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_durable_runs', function (Blueprint $table): void {
            $table->string('execution_mode')->default('durable')->after('topology');
            $table->string('route_start_node_id')->nullable()->after('route_cursor');
            $table->string('current_node_id')->nullable()->after('route_start_node_id');
            $table->json('completed_node_ids')->nullable()->after('current_node_id');
            $table->json('node_states')->nullable()->after('completed_node_ids');
            $table->json('failure')->nullable()->after('node_states');
            $table->unsignedInteger('attempts')->default(0)->after('step_timeout_seconds');
            $table->timestamp('lease_acquired_at')->nullable()->after('attempts');
            $table->unsignedInteger('recovery_count')->default(0)->after('leased_until');
            $table->timestamp('last_recovered_at')->nullable()->after('recovery_count');
            $table->timestamp('paused_at')->nullable()->after('pause_requested_at');
            $table->timestamp('resumed_at')->nullable()->after('paused_at');
            $table->timestamp('cancelled_at')->nullable()->after('cancel_requested_at');
            $table->timestamp('timed_out_at')->nullable()->after('cancelled_at');

            $table->index('current_node_id');
        });
    }

    public function down(): void
    {
        Schema::table('swarm_durable_runs', function (Blueprint $table): void {
            $table->dropIndex(['current_node_id']);
            $table->dropColumn([
                'execution_mode',
                'route_start_node_id',
                'current_node_id',
                'completed_node_ids',
                'node_states',
                'failure',
                'attempts',
                'lease_acquired_at',
                'recovery_count',
                'last_recovered_at',
                'paused_at',
                'resumed_at',
                'cancelled_at',
                'timed_out_at',
            ]);
        });
    }
};
