<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_durable_runs', function (Blueprint $table): void {
            $table->string('run_id')->primary();
            $table->string('swarm_class');
            $table->string('topology');
            $table->string('status');
            $table->unsignedInteger('next_step_index')->default(0);
            $table->unsignedInteger('current_step_index')->nullable();
            $table->unsignedInteger('total_steps');
            $table->timestamp('timeout_at');
            $table->unsignedInteger('step_timeout_seconds');
            $table->string('execution_token')->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->timestamp('pause_requested_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->string('queue_connection')->nullable();
            $table->string('queue_name')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('leased_until');
            $table->index('finished_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_durable_runs');
    }
};
