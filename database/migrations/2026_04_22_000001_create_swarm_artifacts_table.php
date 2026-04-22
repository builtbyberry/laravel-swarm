<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->string('name');
            $table->longText('content');
            $table->json('metadata');
            $table->string('step_agent_class')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_artifacts');
    }
};

