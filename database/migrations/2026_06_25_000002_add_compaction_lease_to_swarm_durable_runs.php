<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('swarm.tables.durable', 'swarm_durable_runs');

        Schema::table($table, function (Blueprint $table): void {
            $table->string('compaction_token', 36)->nullable()->after('execution_token');
            $table->timestamp('compaction_leased_until')->nullable()->index()->after('compaction_token');
            $table->timestamp('compaction_quarantined_at')->nullable()->after('compaction_leased_until');
        });
    }

    public function down(): void
    {
        $table = (string) config('swarm.tables.durable', 'swarm_durable_runs');

        Schema::table($table, function (Blueprint $table): void {
            // SQLite rebuilds the table on dropColumn and errors if the index
            // definition still references the dropped column; drop the index first.
            $table->dropIndex('swarm_durable_runs_compaction_leased_until_index');
            $table->dropColumn(['compaction_token', 'compaction_leased_until', 'compaction_quarantined_at']);
        });
    }
};
