<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_contexts', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('artifacts');
            $table->index('expires_at');
        });

        Schema::table('swarm_artifacts', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('step_agent_class');
            $table->index('expires_at');
        });

        Schema::table('swarm_run_histories', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('finished_at');
            $table->string('execution_token')->nullable()->after('expires_at');
            $table->timestamp('leased_until')->nullable()->after('execution_token');
            $table->index('expires_at');
            $table->index('leased_until');
        });
    }

    public function down(): void
    {
        Schema::table('swarm_run_histories', function (Blueprint $table): void {
            $table->dropIndex(['leased_until']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn('leased_until');
            $table->dropColumn('execution_token');
            $table->dropColumn('expires_at');
        });

        Schema::table('swarm_artifacts', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });

        Schema::table('swarm_contexts', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
