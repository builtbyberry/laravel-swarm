<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'swarm_durable_runs';

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('coordination_profile')->default('step_durable')->after('execution_mode');
            $table->index('coordination_profile', 'swarm_durable_runs_coordination_profile_idx');
        });

        DB::table($tableName)->update(['coordination_profile' => 'step_durable']);
    }

    public function down(): void
    {
        Schema::table('swarm_durable_runs', function (Blueprint $table): void {
            $table->dropIndex('swarm_durable_runs_coordination_profile_idx');
            $table->dropColumn('coordination_profile');
        });
    }
};
