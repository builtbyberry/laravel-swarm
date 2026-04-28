<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_stream_events', function (Blueprint $table): void {
            $table->index(['run_id', 'id'], 'swarm_stream_events_run_id_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('swarm_stream_events', function (Blueprint $table): void {
            $table->dropIndex('swarm_stream_events_run_id_id_index');
        });
    }
};
