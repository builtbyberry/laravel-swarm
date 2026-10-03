<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $nativeInputs = config('swarm.tables.native_inputs', 'swarm_native_inputs');
        $contexts = config('swarm.tables.contexts', 'swarm_contexts');

        if (! Schema::hasTable($nativeInputs)) {
            Schema::create($nativeInputs, function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('run_id')->index();
                $table->unsignedSmallInteger('format_version');
                $table->string('state');
                $table->longText('payload');
                $table->string('payload_hash', 64);
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable($contexts) && ! Schema::hasColumn($contexts, 'native_input_ref')) {
            Schema::table($contexts, function (Blueprint $table): void {
                $table->string('native_input_ref')->nullable();
            });
        }
    }

    public function down(): void
    {
        $contexts = config('swarm.tables.contexts', 'swarm_contexts');
        if (Schema::hasTable($contexts) && Schema::hasColumn($contexts, 'native_input_ref')) {
            Schema::table($contexts, function (Blueprint $table): void {
                $table->dropColumn('native_input_ref');
            });
        }

        Schema::dropIfExists(config('swarm.tables.native_inputs', 'swarm_native_inputs'));
    }
};
