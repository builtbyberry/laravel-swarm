<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_durable_branches', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->string('branch_id');
            $table->unsignedInteger('step_index');
            $table->string('node_id')->nullable()->index();
            $table->string('agent_class');
            $table->string('parent_node_id')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->longText('input');
            $table->longText('output')->nullable();
            $table->json('usage')->nullable();
            $table->json('metadata')->nullable();
            $table->json('failure')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('execution_token')->nullable();
            $table->timestamp('lease_acquired_at')->nullable();
            $table->timestamp('leased_until')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('queue_connection')->nullable();
            $table->string('queue_name')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['run_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_durable_branches');
    }
};
