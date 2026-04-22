<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_contexts', function (Blueprint $table): void {
            $table->string('run_id')->primary();
            $table->text('input');
            $table->json('data');
            $table->json('metadata');
            $table->json('artifacts');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_contexts');
    }
};

