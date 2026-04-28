<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_stream_events', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->string('event_type')->index();
            $table->json('payload');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_stream_events');
    }
};
