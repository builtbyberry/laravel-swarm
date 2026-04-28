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
            $table->index(['status', 'finished_at', 'updated_at'], 'swarm_durable_runs_recovery_idx');
            $table->index(['status', 'finished_at', 'current_node_id', 'updated_at'], 'swarm_durable_runs_waiting_join_idx');
        });

        Schema::table('swarm_durable_branches', function (Blueprint $table): void {
            $table->index(['status', 'updated_at', 'leased_until'], 'swarm_durable_branches_recovery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('swarm_durable_branches', function (Blueprint $table): void {
            $table->dropIndex('swarm_durable_branches_recovery_idx');
        });

        Schema::table('swarm_durable_runs', function (Blueprint $table): void {
            $table->dropIndex('swarm_durable_runs_waiting_join_idx');
            $table->dropIndex('swarm_durable_runs_recovery_idx');
        });
    }
};
