<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('swarm.tables.native_inputs', 'swarm_native_inputs'), function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('run_id')->index();
            $table->unsignedSmallInteger('format_version');
            $table->string('state')->index();
            $table->longText('payload');
            $table->string('payload_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::table(config('swarm.tables.contexts', 'swarm_contexts'), function (Blueprint $table): void {
            $table->string('native_input_ref')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(config('swarm.tables.contexts', 'swarm_contexts'), function (Blueprint $table): void {
            $table->dropColumn('native_input_ref');
        });

        Schema::dropIfExists(config('swarm.tables.native_inputs', 'swarm_native_inputs'));
    }
};
