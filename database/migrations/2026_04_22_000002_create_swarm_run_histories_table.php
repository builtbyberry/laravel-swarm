<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_run_histories', function (Blueprint $table): void {
            $table->string('run_id')->primary();
            $table->string('swarm_class');
            $table->string('topology');
            $table->string('status');
            $table->json('context');
            $table->json('metadata');
            $table->json('steps');
            $table->longText('output')->nullable();
            $table->json('usage');
            $table->json('error')->nullable();
            $table->json('artifacts');
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_run_histories');
    }
};

