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
            $table->json('route_plan')->nullable()->after('total_steps');
            $table->json('route_cursor')->nullable()->after('route_plan');
        });

        Schema::create('swarm_durable_node_outputs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id');
            $table->string('node_id');
            $table->longText('output');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['run_id', 'node_id']);
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_durable_node_outputs');

        Schema::table('swarm_durable_runs', function (Blueprint $table): void {
            $table->dropColumn(['route_plan', 'route_cursor']);
        });
    }
};
