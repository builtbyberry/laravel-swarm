<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->unsignedInteger('step_index');
            $table->string('agent_class');
            $table->longText('input');
            $table->longText('output');
            $table->json('artifacts');
            $table->json('metadata');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['run_id', 'step_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_run_steps');
    }
};
